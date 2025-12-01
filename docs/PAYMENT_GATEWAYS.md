# Payment Gateway Integration

This document explains how to configure and use the payment gateway integration for Stripe, Square, and PayPal.

## Overview

The payment system supports three major payment gateways:
- **Stripe** - Credit card processing and checkout sessions
- **Square** - Point of sale and online payments
- **PayPal** - PayPal payments and checkout

## Installation

### 1. Install Required Dependencies

```bash
# For Stripe
composer require stripe/stripe-php

# For Square
composer require square/square

# For PayPal
composer require paypal/rest-api-sdk-php
```

### 2. Configure Environment Variables

Add your payment gateway credentials to your `.env` file:

```env
# Default gateway (stripe, square, or paypal)
PAYMENT_DEFAULT_GATEWAY=stripe
PAYMENT_CURRENCY=USD

# Stripe Configuration
STRIPE_ENABLED=true
STRIPE_SECRET_KEY=sk_test_xxxxxxxxxxxxx
STRIPE_PUBLISHABLE_KEY=pk_test_xxxxxxxxxxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxx
STRIPE_TEST_MODE=true

# Square Configuration
SQUARE_ENABLED=false
SQUARE_ACCESS_TOKEN=xxxxxxxxxxxxx
SQUARE_LOCATION_ID=xxxxxxxxxxxxx
SQUARE_WEBHOOK_SIGNATURE_KEY=xxxxxxxxxxxxx
SQUARE_PRODUCTION=false

# PayPal Configuration
PAYPAL_ENABLED=false
PAYPAL_CLIENT_ID=xxxxxxxxxxxxx
PAYPAL_CLIENT_SECRET=xxxxxxxxxxxxx
PAYPAL_WEBHOOK_ID=xxxxxxxxxxxxx
PAYPAL_SANDBOX=true

# Payment URLs
APP_URL=http://localhost
```

## API Endpoints

### Get Available Payment Gateways

```http
GET /api/payment/gateways
Authorization: Bearer {token}
```

**Response:**
```json
{
  "gateways": ["stripe", "square", "paypal"]
}
```

### Create Checkout Session

```http
POST /api/invoices/{id}/checkout
Authorization: Bearer {token}
Content-Type: application/json

{
  "provider": "stripe",
  "success_url": "https://example.com/payment/success",
  "cancel_url": "https://example.com/payment/cancel",
  "currency": "USD"
}
```

**Response:**
```json
{
  "checkout_url": "https://checkout.stripe.com/pay/cs_test_xxxxx",
  "session_id": "cs_test_xxxxxxxxxxxxx",
  "invoice_id": 123,
  "provider": "stripe",
  "data": {
    "session_id": "cs_test_xxxxxxxxxxxxx",
    "payment_intent": "pi_xxxxxxxxxxxxx",
    "expires_at": 1234567890
  }
}
```

### Process Refund

```http
POST /api/invoices/{id}/refund
Authorization: Bearer {token}
Content-Type: application/json

{
  "transaction_id": "pi_xxxxxxxxxxxxx",
  "amount": 50.00,
  "reason": "Customer requested refund"
}
```

**Response:**
```json
{
  "refund_id": "re_xxxxxxxxxxxxx",
  "status": "succeeded",
  "amount": 50.00,
  "message": "Refund processed successfully"
}
```

### Webhook Endpoints (Public)

These endpoints receive payment notifications from the gateways:

```http
POST /api/webhooks/payments/stripe
POST /api/webhooks/payments/square
POST /api/webhooks/payments/paypal
```

## Gateway-Specific Configuration

### Stripe

1. Get your API keys from: https://dashboard.stripe.com/apikeys
2. Create a webhook endpoint in Stripe Dashboard pointing to: `{APP_URL}/api/webhooks/payments/stripe`
3. Select these events to listen for:
   - `checkout.session.completed`
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `charge.refunded`

**Webhook Configuration:**
- URL: `https://yourdomain.com/api/webhooks/payments/stripe`
- Events: Select all payment-related events
- Copy the webhook signing secret to `STRIPE_WEBHOOK_SECRET`

### Square

1. Get your credentials from: https://developer.squareup.com/apps
2. Create a webhook subscription pointing to: `{APP_URL}/api/webhooks/payments/square`
3. Subscribe to these events:
   - `payment.created`
   - `payment.updated`
   - `refund.created`

**Webhook Configuration:**
- URL: `https://yourdomain.com/api/webhooks/payments/square`
- Events: Select payment and refund events
- Copy the signature key to `SQUARE_WEBHOOK_SIGNATURE_KEY`

### PayPal

1. Get your credentials from: https://developer.paypal.com/dashboard/applications
2. Create a webhook pointing to: `{APP_URL}/api/webhooks/payments/paypal`
3. Subscribe to these events:
   - `PAYMENT.SALE.COMPLETED`
   - `PAYMENT.SALE.REFUNDED`
   - `PAYMENT.SALE.DENIED`

**Webhook Configuration:**
- URL: `https://yourdomain.com/api/webhooks/payments/paypal`
- Events: Select payment sale events
- Copy the webhook ID to `PAYPAL_WEBHOOK_ID`

## Usage Examples

### Example 1: Create a Stripe Checkout for an Invoice

```php
// In your controller or service
$invoiceId = 123;
$provider = 'stripe';

$options = [
    'success_url' => 'https://example.com/payment/success?invoice=' . $invoiceId,
    'cancel_url' => 'https://example.com/payment/cancel?invoice=' . $invoiceId,
    'currency' => 'USD',
];

$result = $paymentService->createCheckoutSession($invoiceId, $provider, $options);

// Redirect user to: $result['checkout_url']
```

### Example 2: Handle Webhook

Webhooks are automatically handled by the system. When a payment succeeds:

1. Payment record is created/updated in `payments` table
2. Invoice status is updated to 'paid'
3. Invoice `paid_at` timestamp is set
4. Audit log entry is created

### Example 3: Process a Refund

```php
$invoiceId = 123;
$transactionId = 'pi_xxxxxxxxxxxxx'; // Original payment transaction ID
$amount = 50.00;
$reason = 'Customer requested refund';

$result = $paymentService->refundPayment($invoiceId, $transactionId, $amount, $reason);

// Result contains refund_id, status, amount
```

## Database Tables

### `payment_sessions`
Stores checkout session information:
- `invoice_id` - Foreign key to invoices
- `provider` - Gateway name (stripe, square, paypal)
- `session_id` - Gateway session ID
- `checkout_url` - URL to redirect customer
- `metadata` - JSON data from gateway
- `created_at` - Timestamp

### `payments`
Stores payment records:
- `invoice_id` - Foreign key to invoices
- `amount` - Payment amount
- `method` - Payment gateway used
- `reference` - Transaction ID from gateway
- `status` - Payment status (pending, succeeded, failed, etc.)
- `metadata` - JSON data from gateway
- `created_at` - Timestamp

### `refunds`
Stores refund records:
- `invoice_id` - Foreign key to invoices
- `payment_reference` - Original transaction ID
- `refund_id` - Refund ID from gateway
- `amount` - Refund amount
- `reason` - Refund reason
- `status` - Refund status
- `metadata` - JSON data from gateway
- `created_at` - Timestamp

## Testing

### Test Mode

All gateways support test/sandbox modes:
- **Stripe**: Set `STRIPE_TEST_MODE=true` and use test API keys (starts with `sk_test_`)
- **Square**: Set `SQUARE_PRODUCTION=false` and use sandbox credentials
- **PayPal**: Set `PAYPAL_SANDBOX=true` and use sandbox credentials

### Test Cards

**Stripe Test Cards:**
- Success: `4242 4242 4242 4242`
- Decline: `4000 0000 0000 0002`
- Insufficient funds: `4000 0000 0000 9995`

**Square Test Cards:**
- Success: `4111 1111 1111 1111`
- CVV Decline: `4000 0000 0000 0101`

**PayPal Sandbox:**
- Use sandbox buyer accounts created in PayPal Developer Dashboard

## Security

1. **Webhook Signature Verification**: All webhooks verify the signature/authenticity
2. **HTTPS Required**: Payment gateways require HTTPS in production
3. **Environment Variables**: Never commit API keys to version control
4. **PCI Compliance**: Payment data is handled directly by the gateway (PCI-compliant)

## Troubleshooting

### Gateway Not Available

If a gateway doesn't appear in available gateways:
1. Check the SDK is installed: `composer show | grep stripe/square/paypal`
2. Verify credentials are set in `.env`
3. Check `STRIPE_ENABLED` etc. is set to `true`
4. Review logs for configuration errors

### Webhook Not Working

1. Verify the webhook URL is accessible from the internet
2. Check the webhook secret/signature key is correct
3. Review audit logs for webhook failures
4. Test webhooks using gateway's testing tools

### Payment Declined

1. Check test card numbers are correct
2. Verify test mode is enabled in both app and gateway dashboard
3. Review gateway dashboard for detailed error messages

## Architecture

```
┌─────────────────┐
│ InvoiceController│
└────────┬────────┘
         │
         ▼
┌──────────────────────┐
│PaymentProcessingService│
└────────┬──────────────┘
         │
         ▼
┌──────────────────────┐
│PaymentGatewayFactory│
└────────┬──────────────┘
         │
         ├─────────┬─────────┬─────────┐
         ▼         ▼         ▼         ▼
   StripeGateway SquareGateway PayPalGateway
```

All gateways implement `PaymentGatewayInterface` ensuring consistent behavior.
