# Inventory Search Debugging Guide

## Issue Description
The API endpoint `/api/inventory/search-parts?query=D2224&limit=10` returns null or empty results, even though direct SQL queries show the data exists in the database.

## Code Flow Analysis

### 1. **API Endpoint** (`routes/api.php:1839-1849`)
```php
$router->get('/api/inventory/search-parts', function (Request $request) use ($inventoryController) {
    $user = $request->getAttribute('user');
    $params = [
        'query' => $request->queryParam('query'),
        'vehicle_master_id' => $request->queryParam('vehicle_master_id'),
        'limit' => $request->queryParam('limit'),
    ];

    $data = $inventoryController->searchParts($user, $params);
    return Response::json(['data' => $data]);
});
```
**Returns**: `{"data": [...]}`

### 2. **Controller** (`src/Services/Inventory/InventoryItemController.php:158-173`)
```php
public function searchParts(User $user, array $params = []): array
{
    $this->assertViewAccess($user);  // Throws UnauthorizedException if no access

    $query = $params['query'] ?? '';
    $vehicleMasterId = isset($params['vehicle_master_id']) ? (int) $params['vehicle_master_id'] : null;
    $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 20;

    if (empty($query)) {
        return [];
    }

    $items = $this->repository->searchForParts($query, $vehicleMasterId, $limit);

    return array_map(static fn ($item) => $item->toArray(), $items);
}
```
**Returns**: `array` (never null)

### 3. **Repository** (`src/Services/Inventory/InventoryItemRepository.php:416-463`)
Searches across these fields using `LIKE '%query%'`:
- `inventory_items.name`
- `inventory_items.sku`
- `inventory_items.description`
- `inventory_items.manufacturer_part_number` (if column exists)

**Returns**: `array` (never null)

### 4. **Frontend Service** (`src/services/inventory.service.js:41-48`)
```javascript
async searchParts(query, vehicleMasterId = null, limit = 20) {
    const params = { query, limit }
    if (vehicleMasterId) {
        params.vehicle_master_id = vehicleMasterId
    }
    const response = await api.get('/inventory/search-parts', { params })
    return response.data  // Returns {"data": [...]}
}
```

### 5. **Frontend Component** (`src/views/workorders/WorkorderDetail.vue:1121-1131`)
```javascript
async function searchInventory(query) {
    if (!query || query.length < 2) return []
    try {
        const results = await inventoryService.searchParts(query, null, 10)
        if (!results) return []
        return Array.isArray(results) ? results : (results.data || [])
    } catch (err) {
        console.error('Failed to search inventory:', err)
        return []
    }
}
```

## Potential Issues

### 1. **Authentication/Authorization**
- If user lacks `inventory.view` or `inventory.*` permission, `assertViewAccess()` throws `UnauthorizedException`
- This returns HTTP 403 with `{"error": "User lacks permission to view inventory."}`
- **Check**: Verify user has proper inventory permissions

### 2. **Query Parameter Issues**
- If `query` parameter is empty or null, returns `[]`
- If query length < 2 chars, frontend returns `[]` before API call
- **Check**: Confirm the query param is actually being sent in the HTTP request

### 3. **Database Connection**
- If PDO connection fails, would throw exception
- Exception is caught by Router and returns HTTP 500
- **Check**: Verify database credentials and connection

### 4. **Data Encoding Issues**
- SKU in database might have whitespace or special characters
- Example: `" D2224"` (leading space) or `"D2224 "` (trailing space)
- The LIKE pattern `%D2224%` would still match, but exact match wouldn't
- **Check**: Run the test script to analyze actual SKU values

### 5. **Column Existence**
- If `manufacturer_part_number` column doesn't exist, it's conditionally excluded
- This shouldn't cause failures, but affects search coverage
- **Check**: Verify table schema with test script

## Debugging Steps

### Step 1: Run the Test Script
```bash
php test-inventory-search.php
```

This will:
- Test direct SQL queries
- Test LIKE pattern matching
- Test the repository method directly
- Check database schema
- Analyze for whitespace/encoding issues

### Step 2: Check Browser Network Tab
1. Open browser DevTools → Network tab
2. Search for "D2224" in the workorder popup
3. Find the `/api/inventory/search-parts` request
4. Check:
   - Request URL: Does it include `?query=D2224&limit=10`?
   - Response status: 200 OK, 403 Forbidden, or 500 Error?
   - Response body: What is the actual JSON response?

### Step 3: Check Browser Console
Look for errors logged by the searchInventory function:
```
Failed to search inventory: [error message]
```

### Step 4: Check Server Logs
If using PHP error logging, check for:
```
Router error: [exception message]
```

## Expected Behavior

**Request**:
```
GET /api/inventory/search-parts?query=D2224&limit=10
```

**Successful Response** (200 OK):
```json
{
  "data": [
    {
      "id": 123,
      "name": "Motor Oil 5W-30",
      "sku": "D2224",
      "description": "...",
      "stock_quantity": 50,
      "sale_price": 29.99,
      ...
    }
  ]
}
```

**Empty Results** (200 OK):
```json
{
  "data": []
}
```

**Authorization Error** (403 Forbidden):
```json
{
  "error": "User lacks permission to view inventory."
}
```

## Common Solutions

1. **Empty query**: Ensure query is at least 2 characters
2. **Authentication**: Log in with proper credentials
3. **Permissions**: Ensure user has `inventory.view` or `inventory.*` permission
4. **Whitespace**: Run test script to identify and fix data quality issues
5. **Frontend caching**: Hard refresh browser (Ctrl+Shift+R)
