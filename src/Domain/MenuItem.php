<?php

declare(strict_types=1);

namespace StreamEngine\Domain;

/**
 * MenuItem — the domain model of a menu item.
 *
 * Doesn't know anything about the database.
 * Contains only data and runtime state (active, children).
 */
final class MenuItem
{
    public int $id;
    public ?int $parentId;
    public string $menuGroup;
    public string $type;
    public ?int $pageId;
    public ?string $url;
    /**
     * Frontend-only action name. It is consumed only by type='action' menu
     * items and rendered as a data-* hook by the navigation template.
     */
    public ?string $action;
    public ?string $label;
    public string $accessRule;
    public int $sortOrder;

    public array $children = [];

    public bool $active = false;

    public function __construct(
        int $id,
        ?int $parentId,
        string $menuGroup,
        string $type,
        ?int $pageId,
        ?string $url,
        ?string $action,
        ?string $label,
        string $accessRule,
        int $sortOrder,
    ) {
        $this->id = $id;
        $this->parentId = $parentId;
        $this->menuGroup = $menuGroup;
        $this->type = $type;
        $this->pageId = $pageId;
        $this->url = $url;
        $this->action = $action;
        $this->label = $label;
        $this->accessRule = $accessRule;
        $this->sortOrder = $sortOrder;
    }

    public function isLink(): bool
    {
        return $this->type === 'internal'
            || $this->type === 'external'
            || $this->type === 'dynamic';
    }

    public function isDynamic(): bool
    {
        return $this->type === 'dynamic';
    }

    public function isExternal(): bool
    {
        return $this->type === 'external';
    }

    public function isAction(): bool
    {
        return $this->type === 'action';
    }

    public function isDivider(): bool
    {
        return $this->type === 'divider';
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            parentId: $row['parent'] !== null ? (int) $row['parent'] : null,
            menuGroup: $row['menu_group'],
            type: $row['type'],
            pageId: $row['page_id'] !== null ? (int) $row['page_id'] : null,
            url: $row['url'],
            action: $row['action'],
            label: $row['label'] ?? null,
            accessRule: $row['access_rule'],
            sortOrder: (int) $row['sort_order'],
        );
    }
}
