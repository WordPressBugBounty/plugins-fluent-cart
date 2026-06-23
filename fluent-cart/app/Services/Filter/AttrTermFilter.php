<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Services\Filter\BaseFilter;
use FluentCart\App\Services\Filter\Concerns\HasIdTitleSlugSearch;

class AttrTermFilter extends BaseFilter
{
    use HasIdTitleSlugSearch;
    public string $defaultSortBy = 'serial';
    public string $defaultSortType = 'asc';

    protected function parseSortBy(): string
    {
        return 'serial';
    }

    protected function parseSortType(): string
    {
        return 'asc';
    }

    protected ?int $groupId = null;

    public function setGroupId(int $groupId): self
    {
        $this->groupId = $groupId;
        return $this;
    }

    public function applySelect(): void
    {
        $this->query->select(['id', 'title', 'settings', 'serial', 'created_at']);
    }

    protected function buildCommonQuery()
    {
        if ($this->groupId) {
            $this->query->where('group_id', $this->groupId);
        }

        parent::buildCommonQuery();

        // Default sort is serial ASC, but two terms can share the same serial
        // (the swap-on-reorder logic guarantees uniqueness within a group at
        // the moment of reorder, but bulk imports or partial states can leave
        // ties). Append id ASC as a deterministic tie-breaker so paginated
        // listings stay stable across requests.
        $this->query->orderBy('id', 'ASC');
    }

    public function tabsMap(): array
    {
        return [];
    }

    public function getModel(): string
    {
        return AttributeTerm::class;
    }

    public static function getFilterName(): string
    {
        return 'attr_terms';
    }

    public function applyActiveViewFilter(?string $activeView = null): void
    {
        // Terms have no tab views; this hook is intentionally a no-op.
    }
}
