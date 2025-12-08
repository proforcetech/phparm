# HTTP Routing System

## Overview

The application now includes a complete HTTP routing layer built from scratch, providing a clean and efficient way to handle API requests.

## Architecture

### Core Components

1. **Request** (`src/Support/Http/Request.php`)
   - Captures and wraps incoming HTTP requests
   - Provides methods for accessing query parameters, body data, and headers
   - Supports JSON and form-encoded requests
   - Handles route parameters as attributes

2. **Response** (`src/Support/Http/Response.php`)
   - Provides fluent response building
   - Factory methods for common responses (json, text, html, errors)
   - HTTP status code management
   - Header manipulation

3. **Router** (`src/Support/Http/Router.php`)
   - Handles route registration (GET, POST, PUT, PATCH, DELETE)
   - Pattern matching with named parameters `{id}`, `{name}`, etc.
   - Middleware support (global and route-specific)
   - Error handling with proper HTTP responses

4. **Route** (`src/Support/Http/Route.php`)
   - Individual route representation
   - Pattern matching using regex
   - Middleware chaining
   - Named routes support

5. **Middleware** (`src/Support/Http/Middleware.php`)
   - Authentication middleware
   - Role-based authorization
   - Permission checking with AccessGate
   - CORS support
   - JSON content-type validation

## Usage

### Defining Routes

Routes are defined in `routes/api.php`:

```php
// Simple GET route
$router->get('/api/customers', function (Request $request) {
    return Response::json(['customers' => []]);
});

// Route with parameter
$router->get('/api/customers/{id}', function (Request $request) {
    $id = $request->getAttribute('id');
    return Response::json(['id' => $id]);
});

// POST route with JSON body
$router->post('/api/customers', function (Request $request) {
    $data = $request->body();
    return Response::created($data);
});
```

### Middleware

#### Global Middleware

```php
$router->middleware(Middleware::cors());
```

#### Route Groups with Middleware

```php
// Authenticated routes
$router->group([Middleware::auth()], function (Router $router) {
    $router->get('/api/dashboard', function (Request $request) {
        $user = $request->getAttribute('user');
        return Response::json(['user' => $user->name]);
    });
});

// Role-based routes
$router->group([
    Middleware::auth(),
    Middleware::role('admin', 'manager')
], function (Router $router) {
    $router->get('/api/admin/reports', function (Request $request) {
        return Response::json(['reports' => []]);
    });
});
```

### Request Methods

```php
// Get query parameters
$search = $request->queryParam('search', 'default');

// Get JSON body data
$email = $request->input('email');
$all = $request->body(); // All body data

// Get headers
$token = $request->bearerToken();
$contentType = $request->header('Content-Type');

// Get route parameters
$id = $request->getAttribute('id');

// Get authenticated user (after auth middleware)
$user = $request->getAttribute('user');
```

### Response Methods

```php
// JSON response (most common)
return Response::json(['data' => $data]);

// Created (201)
return Response::created(['id' => 123]);

// No content (204)
return Response::noContent();

// Error responses
return Response::notFound('Customer not found');
return Response::badRequest('Invalid input');
return Response::unauthorized('Login required');
return Response::forbidden('Insufficient permissions');
return Response::serverError('Something went wrong');
```

## Available Endpoints

### Public Endpoints

- `GET /` - API information
- `GET /health` - Health check
- `POST /api/auth/login` - User login
- `POST /api/auth/logout` - User logout

### Authenticated Endpoints

All routes under `/api/*` (except auth) require authentication.

#### Dashboard
- `GET /api/dashboard` - KPIs and metrics
- `GET /api/dashboard/charts` - Chart data

#### Customers
- `GET /api/customers` - List customers
- `GET /api/customers/{id}` - Get customer
- `POST /api/customers` - Create customer
- `PUT /api/customers/{id}` - Update customer
- `DELETE /api/customers/{id}` - Delete customer

#### Service Types
- `GET /api/service-types` - List service types
- `POST /api/service-types` - Create service type
- `PUT /api/service-types/{id}` - Update service type
- `DELETE /api/service-types/{id}` - Delete service type

#### Vehicles
- `GET /api/vehicles` - List vehicles
- `POST /api/vehicles` - Create vehicle

#### Inventory
- `GET /api/inventory` - List inventory items
- `GET /api/inventory/{id}` - Get inventory item
- `POST /api/inventory` - Create inventory item
- `PUT /api/inventory/{id}` - Update inventory item
- `DELETE /api/inventory/{id}` - Delete inventory item

#### Estimates
- `GET /api/estimates` - List estimates
- `GET /api/estimates/{id}` - Get estimate
- `POST /api/estimates` - Create estimate
- `PUT /api/estimates/{id}` - Update estimate

#### Reminders (Admin/Manager only)
- `GET /api/reminders` - List reminder campaigns
- `POST /api/reminders/{id}/activate` - Activate campaign

#### System Health (Admin only)
- `GET /api/system/health` - System health status

## Testing

### Start Development Server

```bash
php -S localhost:8080 -t public
```

### Test Endpoints

```bash
# API info
curl http://localhost:8080/

# Health check
curl http://localhost:8080/health

# Login
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'

# Authenticated request (requires session or bearer token)
curl http://localhost:8080/api/customers \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Error Handling

The router automatically handles errors and returns appropriate JSON responses:

- **400 Bad Request** - Invalid input or validation errors
- **401 Unauthorized** - Missing or invalid authentication
- **403 Forbidden** - Insufficient permissions
- **404 Not Found** - Route not found
- **500 Internal Server Error** - Unhandled exceptions

All errors are logged to error_log for debugging.

## Next Steps

1. [x] Implement remaining controllers for:
   - [x] Invoices (`InvoiceController`, `InvoicePublicController`)
   - [x] Appointments (`AppointmentController`)
   - [x] Inspections (`InspectionController`)
   - [x] Time Tracking (`TimeTrackingController`)
   - [x] Financial Reports (`FinancialController`)
   - [x] Warranty Claims (`WarrantyController`)
   - [x] Credit Accounts (`CreditAccountController`)

2. [x] Add authentication token support (JWT or similar)
   - Bearer token authentication implemented in `Middleware::auth()`
   - Session-based authentication also supported
3. [x] Implement request validation
   - Validators implemented for Vehicle, ServiceType, and domain-specific validations
   - Input validation integrated throughout controllers
4. [ ] Add rate limiting middleware
5. [ ] Set up API documentation (OpenAPI/Swagger)
