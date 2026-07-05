<?php

declare(strict_types=1);

/**
 * Permission UI Metadata
 *
 * Keys must match the MODULE segment of the permission name.
 * For "Action:Module" format  →  key = "Module"   (e.g. "Country", "User")
 * For "module.action" format  →  key = "module"   (e.g. "assessment", "users")
 *
 * Any module NOT listed here will be displayed with auto-generated title & default icon.
 */
return [

    // ── Core Access Control ───────────────────────────────────────────────
    'User' => [
        'title' => 'User Management',
        'icon' => 'heroicon-o-users',
        'order' => 1,
    ],

    'Role' => [
        'title' => 'Role Management',
        'icon' => 'heroicon-o-shield-check',
        'order' => 2,
    ],

    'Permission' => [
        'title' => 'Permission Management',
        'icon' => 'heroicon-o-key',
        'order' => 3,
    ],

    // ── Geography ────────────────────────────────────────────────────────
    'Country' => [
        'title' => 'Countries',
        'icon' => 'heroicon-o-globe-alt',
        'order' => 10,
    ],

    'State' => [
        'title' => 'States',
        'icon' => 'heroicon-o-map-pin',
        'order' => 11,
    ],

    'Currency' => [
        'title' => 'Currencies',
        'icon' => 'heroicon-o-banknotes',
        'order' => 12,
    ],

    'Language' => [
        'title' => 'Languages',
        'icon' => 'heroicon-o-language',
        'order' => 13,
    ],

    // ── Assessment (future) ───────────────────────────────────────────────
    'assessment' => [
        'title' => 'Assessment',
        'icon' => 'heroicon-o-clipboard-document-check',
        'order' => 20,
    ],

    'users' => [
        'title' => 'User Management',
        'icon' => 'heroicon-o-users',
        'order' => 21,
    ],

    'roles' => [
        'title' => 'Role Management',
        'icon' => 'heroicon-o-shield-check',
        'order' => 22,
    ],

];
