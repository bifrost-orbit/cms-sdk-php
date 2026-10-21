<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms;

/**
 * Draws a page's components with your own templates, picked by each component's `type`.
 *
 * Each renderer is given the component (`id`, `type`, `schemaVersion`, `fields`, `children`, `variant`)
 * and this renderer, so it can draw nested blocks with {@see slot()}.
 */
final class BlockRenderer
{
    /**
     * @param array<string, callable(array<string, mixed>, BlockRenderer): string> $components Renderers by component key
     * @param (callable(array<string, mixed>): string)|null $unknown Draws a component with no renderer. Nothing by default.
     */
    public function __construct(
        private readonly array $components,
        private readonly mixed $unknown = null,
    ) {
    }

    /**
     * @param iterable<array<string, mixed>|null> $nodes
     */
    public function render(iterable $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $html .= $this->renderOne($node);
        }
        return $html;
    }

    /**
     * @param array<string, mixed>|null $node
     */
    public function renderOne(?array $node): string
    {
        if ($node === null || !isset($node['type'])) {
            return '';
        }
        $renderer = $this->components[$node['type']] ?? null;
        if ($renderer === null) {
            return $this->unknown !== null ? ($this->unknown)($node) : '';
        }
        return $renderer($node, $this);
    }

    /**
     * Draws the blocks in one of a component's slots, such as the cards in a card grid.
     *
     * @param array<string, mixed> $node
     */
    public function slot(array $node, string $name): string
    {
        $children = $node['children'][$name] ?? [];
        return is_array($children) ? $this->render($children) : '';
    }

    public function has(string $type): bool
    {
        return isset($this->components[$type]);
    }
}
