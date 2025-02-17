<?php
namespace Psalm\Internal\Fork;

use function count;
use function extension_loaded;
use const PHP_EOL;
use function ini_get;
use function version_compare;
use const PHP_VERSION;
use function stream_socket_pair;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use const STREAM_IPPROTO_IP;
use function error_log;
use function pcntl_fork;
use function posix_strerror;
use function posix_get_last_error;
use function array_values;
use function base64_encode;
use function serialize;
use function strlen;
use function fwrite;
use function substr;
use function usleep;
use function fclose;
use function stream_set_blocking;
use function intval;
use function array_fill_keys;
use function array_keys;
use function stream_select;
use function fread;
use function strpos;
use function explode;
use function array_pop;
use function unserialize;
use function base64_decode;
use function gettype;
use function feof;
use function posix_kill;
use const SIGALRM;
use function pcntl_waitpid;
use function pcntl_wifsignaled;
use function pcntl_wexitstatus;
use function pcntl_wtermsig;

/**
 * Adapted with relatively few changes from
 * https://github.com/etsy/phan/blob/1ccbe7a43a6151ca7c0759d6c53e2c3686994e53/src/Phan/ForkPool.php
 *
 * Authors: https://github.com/morria, https://github.com/TysonAndre
 *
 * Fork off to n-processes and divide up tasks between
 * each process.
 */
class Pool
{
    const EXIT_SUCCESS = 1;
    const EXIT_FAILURE = 0;

    /** @var int[] */
    private $child_pid_list = [];

    /** @var resource[] */
    private $read_streams = [];

    /** @var bool */
    private $did_have_error = false;

    /** @var ?\Closure(): void */
    private $task_done_closure;

    public const MAC_PCRE_MESSAGE = 'Mac users: pcre.jit is set to 1 in your PHP config.' . PHP_EOL
        . 'The pcre jit is known to cause segfaults in PHP 7.3 on Macs, and Psalm' . PHP_EOL
        . 'will not execute in threaded mode to avoid indecipherable errors.' . PHP_EOL
        . 'Consider adding pcre.jit=0 to your PHP config.' . PHP_EOL
        . 'Relevant info: https://bugs.php.net/bug.php?id=77260';

    /**
     * @param array<int, array<int, mixed>> $process_task_data_iterator
     * An array of task data items to be divided up among the
     * workers. The size of this is the number of forked processes.
     * @param \Closure $startup_closure
     * A closure to execute upon starting a child
     * @param \Closure(int, mixed):mixed $task_closure
     * A method to execute on each task data.
     * This closure must return an array (to be gathered).
     * @param \Closure():mixed $shutdown_closure
     * A closure to execute upon shutting down a child
     * @param ?\Closure(mixed $data):void $task_done_closure
     * A closure to execute when a task is done
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArgument
     */
    public function __construct(
        array $process_task_data_iterator,
        \Closure $startup_closure,
        \Closure $task_closure,
        \Closure $shutdown_closure,
        ?\Closure $task_done_closure = null
    ) {
        $pool_size = count($process_task_data_iterator);
        $this->task_done_closure = $task_done_closure;

        \assert(
            $pool_size > 1,
            'The pool size must be >= 2 to use the fork pool.'
        );

        if (!extension_loaded('pcntl')) {
            echo(
                'The pcntl extension must be loaded in order for Psalm to be able to use multiple processes.'
                . PHP_EOL
            );
            exit(1);
        }

        if (ini_get('pcre.jit') === '1'
            && \PHP_OS === 'Darwin'
            && version_compare(PHP_VERSION, '7.3.0') >= 0
        ) {
            die(
                self::MAC_PCRE_MESSAGE . PHP_EOL
            );
        }

        // We'll keep track of if this is the parent process
        // so that we can tell who will be doing the waiting
        $is_parent = false;

        $sockets = [];

        // Fork as many times as requested to get the given
        // pool size
        for ($proc_id = 0; $proc_id < $pool_size; ++$proc_id) {
            // Create an IPC socket pair.
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if (!$sockets) {
                error_log('unable to create stream socket pair');
                exit(self::EXIT_FAILURE);
            }

            // Fork
            if (($pid = pcntl_fork()) < 0) {
                error_log(posix_strerror(posix_get_last_error()));
                exit(self::EXIT_FAILURE);
            }

            // Parent
            if ($pid > 0) {
                $is_parent = true;
                $this->child_pid_list[] = $pid;
                $this->read_streams[] = self::streamForParent($sockets);
                continue;
            }

            // Child
            if ($pid === 0) {
                $is_parent = false;
                break;
            }
        }

        // If we're the parent, return
        if ($is_parent) {
            return;
        }

        // Get the write stream for the child.
        $write_stream = self::streamForChild($sockets);

        // Execute anything the children wanted to execute upon
        // starting up
        $startup_closure();

        // Get the work for this process
        $task_data_iterator = array_values($process_task_data_iterator)[$proc_id];

        $task_done_buffer = '';

        foreach ($task_data_iterator as $i => $task_data) {
            $task_result = $task_closure($i, $task_data);
            $task_done_message = new ForkTaskDoneMessage($task_result);
            $serialized_message = $task_done_buffer . base64_encode(serialize($task_done_message)) . PHP_EOL;

            if (strlen($serialized_message) > 200) {
                $bytes_written = @fwrite($write_stream, $serialized_message);

                if (strlen($serialized_message) !== $bytes_written) {
                    $task_done_buffer = substr($serialized_message, $bytes_written);
                } else {
                    $task_done_buffer = '';
                }
            } else {
                $task_done_buffer = $serialized_message;
            }
        }

        // Execute each child's shutdown closure before
        // exiting the process
        $results = $shutdown_closure();

        // Serialize this child's produced results and send them to the parent.
        $process_done_message = new ForkProcessDoneMessage($results ?: []);
        $serialized_message = $task_done_buffer . base64_encode(serialize($process_done_message)) . PHP_EOL;

        $bytes_to_write = strlen($serialized_message);
        $bytes_written = 0;

        while ($bytes_written < $bytes_to_write) {
            // attemt to write the remaining unsent part
            $bytes_written += @fwrite($write_stream, substr($serialized_message, $bytes_written));

            if ($bytes_written < $bytes_to_write) {
                // wait a bit
                usleep(500000);
            }
        }

        fclose($write_stream);

        // Children exit after completing their work
        exit(self::EXIT_SUCCESS);
    }

    /**
     * Prepare the socket pair to be used in a parent process and
     * return the stream the parent will use to read results.
     *
     * @param resource[] $sockets the socket pair for IPC
     *
     * @return resource
     */
    private static function streamForParent(array $sockets)
    {
        list($for_read, $for_write) = $sockets;

        // The parent will not use the write channel, so it
        // must be closed to prevent deadlock.
        fclose($for_write);

        // stream_select will be used to read multiple streams, so these
        // must be set to non-blocking mode.
        if (!stream_set_blocking($for_read, false)) {
            error_log('unable to set read stream to non-blocking');
            exit(self::EXIT_FAILURE);
        }

        return $for_read;
    }

    /**
     * Prepare the socket pair to be used in a child process and return
     * the stream the child will use to write results.
     *
     * @param resource[] $sockets the socket pair for IPC
     *
     * @return resource
     */
    private static function streamForChild(array $sockets)
    {
        list($for_read, $for_write) = $sockets;

        // The while will not use the read channel, so it must
        // be closed to prevent deadlock.
        fclose($for_read);

        return $for_write;
    }

    /**
     * Read the results that each child process has serialized on their write streams.
     * The results are returned in an array, one for each worker. The order of the results
     * is not maintained.
     *
     * @return array
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedOperand
     */
    private function readResultsFromChildren()
    {
        // Create an array of all active streams, indexed by
        // resource id.
        $streams = [];
        foreach ($this->read_streams as $stream) {
            $streams[intval($stream)] = $stream;
        }

        // Create an array for the content received on each stream,
        // indexed by resource id.
        /** @var array<int, string> $content */
        $content = array_fill_keys(array_keys($streams), '');

        $terminationMessages = [];

        // Read the data off of all the stream.
        while (count($streams) > 0) {
            $needs_read = array_values($streams);
            $needs_write = null;
            $needs_except = null;

            // Wait for data on at least one stream.
            $num = stream_select($needs_read, $needs_write, $needs_except, null /* no timeout */);
            if ($num === false) {
                error_log('unable to select on read stream');
                exit(self::EXIT_FAILURE);
            }

            // For each stream that was ready, read the content.
            foreach ($needs_read as $file) {
                $buffer = fread($file, 1024);
                if ($buffer !== false) {
                    $content[intval($file)] .= $buffer;
                }

                if (strpos($buffer, PHP_EOL) !== false) {
                    $serialized_messages = explode(PHP_EOL, $content[intval($file)]);
                    $content[intval($file)] = array_pop($serialized_messages);

                    foreach ($serialized_messages as $serialized_message) {
                        $message = unserialize(base64_decode($serialized_message));

                        if ($message instanceof ForkProcessDoneMessage) {
                            $terminationMessages[] = $message->data;
                        } elseif ($message instanceof ForkTaskDoneMessage) {
                            if ($this->task_done_closure !== null) {
                                ($this->task_done_closure)($message->data);
                            }
                        } else {
                            error_log('Child should return ForkMessage - response type=' . gettype($message));
                            $this->did_have_error = true;
                        }
                    }
                }

                // If the stream has closed, stop trying to select on it.
                if (feof($file)) {
                    if ($content[intval($file)] !== '') {
                        error_log('Child did not send full message before closing the connection');
                        $this->did_have_error = true;
                    }

                    fclose($file);
                    unset($streams[intval($file)]);
                }
            }
        }

        return array_values($terminationMessages);
    }

    /**
     * Wait for all child processes to complete
     *
     * @return array
     */
    public function wait(): array
    {
        // Read all the streams from child processes into an array.
        $content = $this->readResultsFromChildren();

        // Wait for all children to return
        foreach ($this->child_pid_list as $child_pid) {
            $process_lookup = posix_kill($child_pid, 0);

            $status = 0;

            if ($process_lookup) {
                /**
                 * @psalm-suppress UndefinedConstant - does not exist on windows
                 * @psalm-suppress MixedArgument
                 */
                posix_kill($child_pid, SIGALRM);

                if (pcntl_waitpid($child_pid, $status) < 0) {
                    error_log(posix_strerror(posix_get_last_error()));
                }
            }

            // Check to see if the child died a graceful death
            if (pcntl_wifsignaled($status)) {
                $return_code = pcntl_wexitstatus($status);
                $term_sig = pcntl_wtermsig($status);

                /**
                 * @psalm-suppress UndefinedConstant - does not exist on windows
                 */
                if ($term_sig !== SIGALRM) {
                    $this->did_have_error = true;
                    error_log("Child terminated with return code $return_code and signal $term_sig");
                }
            }
        }

        return $content;
    }

    /**
     * Returns true if this had an error, e.g. due to memory limits or due to a child process crashing.
     *
     * @return  bool
     *
     * @psalm-suppress PossiblyUnusedMethod because we may in the future
     */
    public function didHaveError()
    {
        return $this->did_have_error;
    }
}
