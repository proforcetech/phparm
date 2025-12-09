# Frontend Architecture Plan

**STATUS: ✅ COMPLETE - All phases implemented**

## Overview

Building a modern, responsive frontend for the Automotive Repair Shop Management System.

## Technology Stack

### Option 1: Vue.js 3 + Vite (Recommended)
- **Framework**: Vue.js 3 (Composition API)
- **Build Tool**: Vite
- **Styling**: Tailwind CSS
- **State Management**: Pinia
- **HTTP Client**: Axios
- **Routing**: Vue Router
- **Icons**: Heroicons or Font Awesome

**Pros**: Component-based, reactive, great tooling, easy to maintain
**Cons**: Requires build step, learning curve for Vue

### Option 2: Vanilla JavaScript + Alpine.js
- **Framework**: Alpine.js (lightweight reactivity)
- **Build Tool**: Vite
- **Styling**: Tailwind CSS
- **HTTP Client**: Fetch API
- **Routing**: Custom or Page.js
- **Icons**: Heroicons

**Pros**: Lightweight, simple, less abstraction
**Cons**: More manual DOM manipulation, less structured

## Recommended: Vue.js 3 + Vite

We'll proceed with Vue.js 3 for better structure and maintainability.

## Application Structure

```
public/
├── index.html              # SPA entry point
└── assets/                 # Static assets
    ├── images/
    └── fonts/

src/
├── main.js                 # App initialization
├── App.vue                 # Root component
├── router/
│   └── index.js           # Route definitions
├── stores/
│   ├── auth.js            # Authentication state
│   ├── invoices.js        # Invoice state
│   └── appointments.js    # Appointment state
├── views/
│   ├── auth/
│   │   ├── Login.vue
│   │   ├── Register.vue
│   │   └── ForgotPassword.vue
│   ├── dashboard/
│   │   ├── AdminDashboard.vue
│   │   └── CustomerDashboard.vue
│   ├── invoices/
│   │   ├── InvoiceList.vue
│   │   ├── InvoiceDetail.vue
│   │   └── InvoiceCreate.vue
│   ├── appointments/
│   │   ├── AppointmentList.vue
│   │   ├── AppointmentBook.vue
│   │   └── AppointmentCalendar.vue
│   ├── vehicles/
│   │   ├── VehicleList.vue
│   │   └── VehicleDetail.vue
│   ├── customers/
│   │   ├── CustomerList.vue
│   │   └── CustomerDetail.vue
│   └── inventory/
│       ├── InventoryList.vue
│       └── InventoryManage.vue
├── components/
│   ├── layout/
│   │   ├── Navbar.vue
│   │   ├── Sidebar.vue
│   │   └── Footer.vue
│   ├── ui/
│   │   ├── Button.vue
│   │   ├── Card.vue
│   │   ├── Modal.vue
│   │   ├── Table.vue
│   │   └── Form/
│   │       ├── Input.vue
│   │       ├── Select.vue
│   │       └── Textarea.vue
│   └── domain/
│       ├── InvoiceCard.vue
│       ├── AppointmentCard.vue
│       ├── VehicleCard.vue
│       └── CustomerCard.vue
├── services/
│   ├── api.js             # Axios instance
│   ├── auth.service.js    # Auth API calls
│   ├── invoice.service.js
│   ├── appointment.service.js
│   └── vehicle.service.js
├── utils/
│   ├── formatters.js      # Date, currency formatting
│   ├── validators.js      # Form validation
│   └── constants.js       # App constants
└── assets/
    └── styles/
        └── main.css       # Global styles

vite.config.js             # Vite configuration
tailwind.config.js         # Tailwind configuration
package.json               # Dependencies
```

## Key Features

### 1. Authentication
- Login page with email/password
- Customer login with separate form
- Registration for staff (admin only)
- Password reset flow
- JWT token storage in localStorage
- Auto-logout on token expiration

### 2. Admin Dashboard
- ✅ KPIs: Revenue, pending invoices, appointments today
- ✅ Charts: Monthly revenue, top services
- Quick actions: Create invoice, book appointment
- Recent activity feed
- System health status

### 3. Customer Portal
- View own invoices
- Pay invoices online (Stripe/Square/PayPal)
- Book appointments
- View service history
- Manage vehicle information

### 4. Invoice Management
- List all invoices with filters (status, customer, date range)
- Create new invoice from estimate
- View invoice details
- Download PDF
- Create payment checkout
- Process refunds (admin only)
- Mark as paid manually

### 5. Appointment System
- Calendar view (day/week/month)
- Book new appointment
- Check availability
- Assign technicians
- Update appointment status
- Send reminders

### 6. Vehicle Management
- VIN decoder integration
- Vehicle list with search
- Add/edit vehicles
- Link to customer
- Service history per vehicle

### 7. Inventory Management
- Parts catalog
- Stock levels with alerts
- Add/update inventory
- Usage tracking
- Low stock warnings

### 8. Customer Management
- Customer directory
- Customer details with service history
- Credit account management
- Communication log

## Routing Structure

```
Public Routes:
/login                     # Staff login
/customer-login            # Customer login
/register                  # Staff registration (admin only)
/forgot-password           # Password reset

Admin Routes (require auth):
/dashboard                 # Admin dashboard
/invoices                  # Invoice list
/invoices/:id              # Invoice detail
/invoices/create           # Create invoice
/appointments              # Appointment calendar
/appointments/:id          # Appointment detail
/customers                 # Customer list
/customers/:id             # Customer detail
/vehicles                  # Vehicle list
/vehicles/:id              # Vehicle detail
/inventory                 # Inventory management
/reports                   # Reports & analytics
/settings                  # System settings

Customer Routes (require auth):
/portal                    # Customer dashboard
/portal/invoices           # My invoices
/portal/invoices/:id       # Invoice detail & payment
/portal/appointments       # My appointments
/portal/vehicles           # My vehicles
/portal/profile            # Profile settings
```

## State Management

Using Pinia stores for:
- **auth**: Current user, token, permissions
- **invoices**: Invoice list, filters, current invoice
- **appointments**: Appointment list, calendar data
- **vehicles**: Vehicle list, current vehicle
- **customers**: Customer list, current customer
- **ui**: Sidebar open/closed, modal state, notifications

## API Integration

All API calls go through centralized service layer:

```javascript
// services/api.js
import axios from 'axios';

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
  },
});

// Add auth token to requests
api.interceptors.request.use(config => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Handle 401 errors
api.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      // Redirect to login
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default api;
```

## UI/UX Guidelines

### Design System
- **Colors**:
  - Primary: Blue (#3B82F6)
  - Success: Green (#10B981)
  - Warning: Yellow (#F59E0B)
  - Danger: Red (#EF4444)
  - Gray scale: Tailwind default

- **Typography**:
  - Headings: Inter or System font
  - Body: System font stack
  - Monospace: For codes/VINs

- **Spacing**: Tailwind spacing scale (4px base)

### Components
- Consistent button styles (primary, secondary, danger)
- Form inputs with validation states
- Loading states for async operations
- Error/success notifications (toast style)
- Confirmation modals for destructive actions
- Data tables with sorting, filtering, pagination

### Responsive Design
- Mobile-first approach
- Breakpoints:
  - sm: 640px (mobile landscape)
  - md: 768px (tablet)
  - lg: 1024px (desktop)
  - xl: 1280px (large desktop)

- Sidebar collapses to hamburger on mobile
- Tables become cards on mobile
- Forms stack vertically on mobile

## Development Phases

### Phase 1: Foundation (Week 1)
- [x] Set up Vite + Vue 3 project
- [x] Configure Tailwind CSS
- [x] Set up Vue Router
- [x] Set up Pinia stores
- [x] Create API service layer
- [x] Build authentication flow
- [x] Create base layout components

### Phase 2: Admin Core (Week 2)
- [x] Admin dashboard
- [x] Invoice list/detail views
- [x] Invoice creation form
- [x] Estimate list/detail views
- [x] Estimate creation form
- [x] Estimate to invoice conversion
- [x] PDF download integration
- [x] Payment checkout integration
- [x] Revenue and service type charts
- [x] Chart.js integration

### Phase 3: Appointments & Vehicles (Week 3)
- [x] Appointment list view with filters
- [x] Appointment calendar (day/week/month views)
- [x] Appointment booking form
- [x] Vehicle list/detail
- [x] VIN decoder integration
- [x] Customer list/detail

### Phase 4: Customer Portal (Week 4)
- [x] Customer dashboard
- [x] Customer invoice view
- [x] Online payment flow
- [x] Customer appointment booking
- [x] Customer vehicle management

### Phase 5: Polish & Optimization (Week 5)
- [x] Inventory management
- [x] Reports & analytics with charts
- [x] Settings pages
- [x] Mobile optimization
- [x] Performance optimization
- [x] Testing & bug fixes

## Installation Commands

```bash
# Initialize Node.js project
npm init -y

# Install Vue 3 + Vite
npm create vite@latest frontend -- --template vue

# Install dependencies
cd frontend
npm install

# Install additional packages
npm install vue-router@4 pinia axios
npm install -D tailwindcss postcss autoprefixer
npm install @heroicons/vue

# Initialize Tailwind
npx tailwindcss init -p

# Development server
npm run dev

# Build for production
npm run build
```

## Next Steps

1. Initialize Vite + Vue 3 project
2. Configure Tailwind CSS
3. Create base layout and authentication
4. Build admin dashboard
5. Implement invoice management UI
6. Add appointment booking system
7. Create customer portal

This provides a solid foundation for a modern, maintainable frontend application.
