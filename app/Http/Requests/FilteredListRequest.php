<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class FilteredListRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'page_size' => ['sometimes', 'integer', 'between:1,100'],
            'sort_by' => ['sometimes', 'string', Rule::in(array_keys($this->sortableColumns()))],
            'sort_direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function pageSize(): int
    {
        return (int) $this->validated('page_size', 15);
    }

    public function sortColumn(): string
    {
        $sortBy = (string) $this->validated('sort_by', $this->defaultSortBy());

        return $this->sortableColumns()[$sortBy];
    }

    public function sortDirection(): string
    {
        return (string) $this->validated('sort_direction', 'desc');
    }

    /** @return array<string, array<int, mixed>> */
    abstract protected function filterRules(): array;

    /** @return array<string, string> */
    abstract protected function sortableColumns(): array;

    protected function defaultSortBy(): string
    {
        return 'created_at';
    }
}
