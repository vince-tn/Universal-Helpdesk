<?php

use CodeIgniter\Router\RouteCollection;

$routes->get('/', 'Home::index');

$routes->group('', ['filter' => 'guest'], static function (RouteCollection $routes) {
    $routes->get('login', 'AuthController::loginForm');
    $routes->post('login', 'AuthController::login');

    $routes->get('signup', 'AuthController::signupForm');
    $routes->post('signup', 'AuthController::signup');
    $routes->get('signup/verify', 'AuthController::verifyForm');
    $routes->post('signup/verify', 'AuthController::verify');
    $routes->post('signup/resend', 'AuthController::resend');
});

$routes->post('logout', 'AuthController::logout', ['filter' => 'auth']);

$routes->get('uploads/tickets/(:segment)', 'AttachmentController::show/$1', ['filter' => 'auth']);

$routes->group('tickets', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'TicketController::index');
    $routes->get('new', 'TicketController::create');
    $routes->post('/', 'TicketController::store');
    $routes->post('attachments', 'AttachmentController::store');
    $routes->get('export', 'TicketController::export');
    $routes->post('(:segment)/status', 'TicketController::updateStatus/$1');
    $routes->post('(:segment)/meta', 'TicketController::updateMeta/$1');
    $routes->post('(:segment)/messages', 'TicketController::postMessage/$1');

    $routes->post('(:segment)/assist', 'TicketController::assist/$1');
    $routes->get('(:segment)', 'TicketController::show/$1');
});

$routes->get('reports', 'ReportsController::index', ['filter' => 'auth:superadmin']);

$routes->group('notifications', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'NotificationController::index');
    $routes->get('(:num)', 'NotificationController::open/$1');
    $routes->get('mentionable', 'NotificationController::mentionable');
});

$routes->group('admin/users', ['filter' => 'auth:superadmin'], static function (RouteCollection $routes) {
    $routes->get('/', 'AdminUserController::index');
    $routes->get('new', 'AdminUserController::create');
    $routes->post('/', 'AdminUserController::store');
    $routes->get('(:num)/edit', 'AdminUserController::edit/$1');
    $routes->post('(:num)', 'AdminUserController::update/$1');
    $routes->post('(:num)/delete', 'AdminUserController::delete/$1');
    $routes->post('(:num)/toggle', 'AdminUserController::deactivate/$1');
});

$routes->group('admin/departments', ['filter' => 'auth:superadmin'], static function (RouteCollection $routes) {
    $routes->get('/', 'AdminDepartmentController::index');
    $routes->post('/', 'AdminDepartmentController::store');
    $routes->post('(:num)', 'AdminDepartmentController::update/$1');
    $routes->post('(:num)/delete', 'AdminDepartmentController::delete/$1');
    $routes->post('(:num)/roles', 'AdminDepartmentController::storeRole/$1');
    $routes->post('roles/(:num)', 'AdminDepartmentController::updateRole/$1');
    $routes->post('roles/(:num)/delete', 'AdminDepartmentController::deleteRole/$1');
});
