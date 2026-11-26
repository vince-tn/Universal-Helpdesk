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

    $routes->get('forgot', 'AuthController::forgotForm');
    $routes->post('forgot', 'AuthController::forgot');
    $routes->get('forgot/code', 'AuthController::forgotCodeForm');
    $routes->post('forgot/code', 'AuthController::forgotCode');
    $routes->post('forgot/resend', 'AuthController::forgotResend');
    $routes->get('forgot/new', 'AuthController::resetForm');
    $routes->post('forgot/new', 'AuthController::reset');

    $routes->get('login/mfa', 'MfaController::challengeForm');
    $routes->post('login/mfa', 'MfaController::challenge');

    $routes->get('sso/login', 'SsoController::login');
    $routes->get('sso/callback', 'SsoController::callback');
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
    $routes->post('(:segment)/classify', 'TicketController::classify/$1');
    $routes->post('(:segment)/approval', 'TicketController::approval/$1');
    $routes->get('(:segment)/feedback', 'TicketController::feedback/$1');
    $routes->get('(:segment)/updates', 'TicketController::updates/$1');
    $routes->get('(:segment)', 'TicketController::show/$1');
});

$routes->get('reports', 'ReportsController::index', ['filter' => 'auth:superadmin']);
$routes->get('reports/export', 'ReportsController::export', ['filter' => 'auth:superadmin']);

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
    $routes->post('(:num)/mfa-reset', 'AdminUserController::resetMfa/$1');
});

$routes->group('admin/access', ['filter' => 'auth:superadmin'], static function (RouteCollection $routes) {
    $routes->get('/', 'AdminAccessController::index');
    $routes->post('/', 'AdminAccessController::store');
    $routes->post('(:num)/delete', 'AdminAccessController::delete/$1');
});

$routes->group('admin/departments', ['filter' => 'auth:superadmin,supervisor,team_leader'], static function (RouteCollection $routes) {
    $routes->get('/', 'AdminDepartmentController::index');
    $routes->post('/', 'AdminDepartmentController::store');
    $routes->post('(:num)', 'AdminDepartmentController::update/$1');
    $routes->post('(:num)/delete', 'AdminDepartmentController::delete/$1');
    $routes->post('bulk', 'AdminDepartmentController::bulk');
    $routes->post('(:num)/roles', 'AdminDepartmentController::storeRole/$1');
    $routes->post('roles/(:num)', 'AdminDepartmentController::updateRole/$1');
    $routes->post('roles/(:num)/delete', 'AdminDepartmentController::deleteRole/$1');
});

$routes->group('admin/departments', ['filter' => 'auth:superadmin,supervisor,team_leader,agent'], static function (RouteCollection $routes) {
    $routes->get('(:num)/knowledge', 'AdminKnowledgeController::index/$1');
    $routes->post('(:num)/knowledge', 'AdminKnowledgeController::storeArticle/$1');
    $routes->get('knowledge/(:num)/history', 'AdminKnowledgeController::history/$1');
    $routes->post('knowledge/(:num)', 'AdminKnowledgeController::updateArticle/$1');
    $routes->post('knowledge/(:num)/delete', 'AdminKnowledgeController::deleteArticle/$1');
    $routes->post('(:num)/guardrails', 'AdminKnowledgeController::storeGuardrail/$1');
    $routes->post('guardrails/(:num)/delete', 'AdminKnowledgeController::deleteGuardrail/$1');
});

$routes->group('admin/knowledge-review', ['filter' => 'auth:superadmin,supervisor,team_leader'], static function (RouteCollection $routes) {
    $routes->get('/', 'KnowledgeReviewController::index');
    $routes->post('(:num)', 'KnowledgeReviewController::decide/$1');
});

$routes->group('admin/sla', ['filter' => 'auth:superadmin'], static function (RouteCollection $routes) {
    $routes->get('/', 'AdminSlaController::index');
    $routes->post('/', 'AdminSlaController::save');
    $routes->post('signoff', 'AdminSlaController::signoff');
});

$routes->group('account', ['filter' => 'auth'],static function (RouteCollection $routes) {
    $routes->get('/', 'AccountController::index');
    $routes->post('/', 'AccountController::update');
    $routes->get('mfa/setup', 'MfaController::setupForm');
    $routes->post('mfa/setup', 'MfaController::setup');
    $routes->post('mfa/disable', 'MfaController::disable');
    $routes->post('mfa/recovery', 'MfaController::regenerate');
    $routes->get('password', 'AccountController::passwordForm');
    $routes->post('password', 'AccountController::changePassword');
});

$routes->group('rooms/manage', ['filter' => 'auth:superadmin'], static function (RouteCollection $routes) {
    $routes->get('/', 'RoomController::manage');
    $routes->post('/', 'RoomController::manageStore');
    $routes->post('(:num)', 'RoomController::manageUpdate/$1');
    $routes->post('(:num)/toggle', 'RoomController::manageToggle/$1');
});

$routes->group('rooms', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'RoomController::index');
    $routes->get('week', 'RoomController::week');
    $routes->get('mine', 'RoomController::mine');
    $routes->get('book', 'RoomController::create');
    $routes->post('book', 'RoomController::store');
    $routes->get('bookings/(:num)/ics', 'RoomController::ics/$1');
    $routes->post('bookings/(:num)/cancel', 'RoomController::cancel/$1');
    $routes->post('bookings/(:num)/checkin', 'RoomController::checkin/$1');
    $routes->post('bookings/(:num)/approve', 'RoomController::approve/$1');
    $routes->post('bookings/(:num)/reject', 'RoomController::reject/$1');
});
