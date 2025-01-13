<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

class RabbitMQPublishFinder extends NodeVisitorAbstract
{
    private string $className = 'RabbitMQ';
    private string $methodName = 'publish';

    private string $directory;
    private array $usages = [];

    /**
     * RabbitMQPublishFinder constructor.
     * @param string $directory
     */
    public function __construct(string $directory = 'App')
    {
        $this->directory = $directory;
    }

    /**
     * Find usages of RabbitMQ::publish() in the given directory
     *
     * @return array
     */
    public function enterNode(Node $node)
    {
        if (
            $node instanceof Node\Expr\StaticCall &&
            $node->class instanceof Node\Name && $node->class->toString() === $this->className &&
            $node->name instanceof Node\Identifier && $node->name->name === $this->methodName
        ) {
            $this->usages[] = [
                'args' => $this->extractArguments($node->args),
                'line' => $node->getLine(),
            ];
        }
    }

    /**
     * Extract arguments from the method call
     *
     * @param array $args
     * @return array
     */
    private function extractArguments(array $args): array
    {
        return array_map(function ($arg) {
            $valueNode = $arg->value;

            if ($valueNode instanceof Node\Scalar\String_) {
                return $valueNode->value; // Regular strings
            } elseif ($valueNode instanceof Node\Scalar\LNumber || $valueNode instanceof Node\Scalar\DNumber) {
                return $valueNode->value; // Numbers
            } elseif ($valueNode instanceof Node\Scalar\InterpolatedString) {
                // Handle interpolated strings (return the full string representation)
                return $this->interpolatedStringToString($valueNode);
            } elseif ($valueNode instanceof Node\Expr\Variable) {
                return '$' . $valueNode->name; // Variable names
            } elseif ($valueNode instanceof Node\Expr\Array_) {
                return 'Array'; // Handle arrays
            } else {
                return 'Unknown'; // Fallback for unsupported cases
            }
        }, $args);
    }

    /**
     * Convert an interpolated string to a readable format
     *
     * @param Node\Scalar\InterpolatedString $node
     * @return string
     */
    private function interpolatedStringToString(Node\Scalar\InterpolatedString $node): string
    {
        // Convert the interpolated string to a readable format
        $parts = array_map(function ($part) {
            if ($part instanceof Node\Scalar\EncapsedStringPart) {
                return $part->value; // Plain text part
            } elseif ($part instanceof Node\Expr\Variable) {
                return '${' . $part->name . '}'; // Interpolated variable
            } elseif ($part instanceof Node\Expr) {
                return '{expr}'; // For complex expressions
            }
            return '';
        }, $node->parts);

        return implode('', $parts);
    }

    /**
     * Get the usages of RabbitMQ::publish()
     *
     * @return array
     */
    public function getUsages()
    {
        return $this->usages;
    }
}