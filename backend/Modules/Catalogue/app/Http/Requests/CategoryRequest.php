<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Models\Category;

/** Create or update a category. Two levels only: category → subcategory. */
class CategoryRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::CATALOGUE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'parentId' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('parent_id')],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $category = $this->route('category');
            if (! $category instanceof Category || ! $this->filled('parentId')) {
                return;
            }

            if ((int) $this->input('parentId') === $category->id) {
                $validator->errors()->add('parentId', 'A category cannot be its own parent.');
            } elseif ($category->children()->exists()) {
                $validator->errors()->add('parentId', 'A category with subcategories cannot become a subcategory.');
            }
        }];
    }
}
