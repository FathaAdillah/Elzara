<?php

declare(strict_types=1);

$actions = [];
foreach (['user', 'catalog', 'content', 'batch', 'billing', 'order', 'inventory', 'cart', 'payment', 'shipment', 'report', 'logistics', 'promotion', 'sourcing', 'procurement', 'marketplace', 'review', 'setting', 'notification', 'ticket', 'audit'] as $prefix) {
    $actions[$prefix] = ['view', 'create', 'update', 'delete'];
}
foreach (['user', 'billing', 'order', 'payment', 'marketplace'] as $prefix) {
    $actions[$prefix] = ['view', 'create', 'update'];
}
$actions['review'] = ['view', 'update', 'delete'];
$actions['report'] = $actions['audit'] = ['view'];
$actions['billing'][] = 'void';
$actions['order'][] = 'force_transition';
$actions['batch'][] = 'close';
$actions['payment'][] = 'mark_paid_manual';

return [
    'actions' => $actions,
    // Bundles follow Doc 06 §1.3/§2 and remain provisional (Q-8).
    'bundles' => [
        'superadmin' => ['*'],
        'catalog-manager' => ['catalog.*', 'content.*', 'promotion.*'],
        'sales-ops' => ['order.*', 'cart.view', 'payment.view', 'user.view'],
        'preorder-ops' => ['batch.*', 'order.view', 'inventory.view'],
        'sourcing-ops' => ['sourcing.*', 'procurement.view', 'marketplace.view'],
        'warehouse-staff' => ['inventory.*', 'shipment.*', 'logistics.view'],
        'procurement-staff' => ['procurement.*', 'sourcing.view', 'billing.view', 'logistics.*'],
        'finance' => ['billing.*', 'payment.*', 'report.*', 'order.view', 'marketplace.*'],
        'customer-support' => ['ticket.*', 'user.view', 'user.update', 'order.view', 'review.*', 'marketplace.*'],
        'analyst' => ['report.*', '*.view'],
    ],
];
