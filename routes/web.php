<?php
declare(strict_types=1);
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BookingController;
use App\Controllers\HomeController;
use App\Controllers\HostController;
use App\Controllers\ListingController;
use App\Controllers\ProfileController;
use App\Controllers\WishlistController;
use Core\Router;
$router=new Router();
$router->get('/',[HomeController::class,'index']);
$router->get('/api/listings',[HomeController::class,'search']);
$router->get('/login',[AuthController::class,'loginForm']);$router->post('/login',[AuthController::class,'login']);
$router->get('/register',[AuthController::class,'registerForm']);$router->post('/register',[AuthController::class,'register']);$router->post('/logout',[AuthController::class,'logout']);
$router->get('/profile',[ProfileController::class,'show']);$router->post('/profile',[ProfileController::class,'update']);$router->post('/profile/become-host',[ProfileController::class,'becomeHost']);
$router->get('/listings/{id}',[ListingController::class,'show']);$router->get('/api/listings/{id}/quote',[ListingController::class,'quote']);$router->post('/listings/{id}/book',[BookingController::class,'create']);
$router->get('/bookings',[BookingController::class,'index']);$router->post('/bookings/{id}/cancel',[BookingController::class,'cancel']);$router->post('/bookings/{id}/review',[BookingController::class,'review']);
$router->get('/wishlist',[WishlistController::class,'index']);$router->post('/api/wishlist/{id}',[WishlistController::class,'toggle']);
$router->get('/host',[HostController::class,'dashboard']);$router->get('/host/listings/create',[HostController::class,'createForm']);$router->post('/host/listings',[HostController::class,'store']);$router->get('/host/listings/{id}/edit',[HostController::class,'editForm']);$router->get('/host/listings/{id}/availability',[HostController::class,'availabilityPage']);$router->post('/host/listings/{id}',[HostController::class,'update']);$router->post('/host/listings/{id}/visibility',[HostController::class,'visibility']);$router->post('/api/host/listings/{id}/availability',[HostController::class,'availability']);$router->post('/host/bookings/{id}/transition',[HostController::class,'transition']);
$router->get('/admin',[AdminController::class,'dashboard']);$router->post('/admin/users',[AdminController::class,'userCreate']);$router->get('/admin/users/{id}/edit',[AdminController::class,'userEdit']);$router->post('/admin/users/{id}',[AdminController::class,'userUpdate']);$router->post('/admin/users/{id}/delete',[AdminController::class,'userDelete']);$router->post('/admin/users/{id}/status',[AdminController::class,'userStatus']);$router->post('/admin/users/{id}/roles',[AdminController::class,'userRoles']);$router->post('/admin/listings/{id}/moderate',[AdminController::class,'moderate']);$router->get('/admin/listings/{id}/edit',[AdminController::class,'listingEdit']);$router->post('/admin/listings/{id}',[AdminController::class,'listingUpdate']);$router->post('/admin/listings/{id}/visibility',[AdminController::class,'listingVisibility']);$router->post('/admin/listings/{id}/delete',[AdminController::class,'listingDelete']);$router->post('/admin/bookings/{id}/transition',[AdminController::class,'bookingTransition']);
return $router;

