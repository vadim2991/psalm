<?php declare(strict_types=1);

namespace Psalm\Internal\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use function array_map;

/**
 * Visitor cloning all nodes and linking to the original nodes using an attribute.
 *
 * This visitor is required to perform format-preserving pretty prints.
 */
class CloningVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $origNode)
    {
        $node = clone $origNode;
        if ($cs = $node->getComments()) {
            $node->setAttribute(
                'comments',
                array_map(
                    /**
                     * @return \PhpParser\Comment
                     */
                    function (\PhpParser\Comment $c) {
                        return clone $c;
                    },
                    $cs
                )
            );
        }
        return $node;
    }
}
