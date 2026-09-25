<?php

namespace Modules\Authorization\Services;

use Illuminate\Support\Collection;
use Modules\Auth\Models\User;
use Modules\Authorization\Models\Menu;

class MenuService
{
    /**
     * Menu tree visible to the user. An item is visible when it has no
     * permission or the user holds it; a group without visible children
     * and without its own view is dropped.
     *
     * @return list<array<string, mixed>>
     */
    public function treeFor(User $user): array
    {
        $menus = Menu::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (Menu $menu) => $menu->parent_id ?? 0);

        return $this->build($menus, 0, $user);
    }

    /**
     * @param  Collection<int|string, Collection<int, Menu>>  $byParent
     * @return list<array<string, mixed>>
     */
    private function build(Collection $byParent, int $parentId, User $user): array
    {
        $items = [];

        foreach ($byParent->get($parentId, collect()) as $menu) {
            if ($menu->permission !== null && ! $user->can($menu->permission)) {
                continue;
            }

            $children = $this->build($byParent, $menu->id, $user);

            if ($menu->view_type === null && $children === []) {
                continue;
            }

            $items[] = [
                'key' => $menu->key,
                'title' => $menu->title,
                'icon' => $menu->icon,
                'viewType' => $menu->view_type,
                'path' => $menu->path,
                'children' => $children,
            ];
        }

        return $items;
    }
}
