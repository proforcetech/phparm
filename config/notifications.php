<?php

return [
    'mail' => [
        'default' => env('MAIL_DRIVER', 'log'),
        'from_name' => env('MAIL_FROM_NAME', null),
        'from_address' => env('MAIL_FROM_ADDRESS', null),
        'drivers' => [
            'log' => [],
            'smtp' => [
                'host' => env('MAIL_HOST', 'smtp'),
                'port' => env('MAIL_PORT', 587),
                'username' => env('MAIL_USERNAME', null),
                'password' => env('MAIL_PASSWORD', null),
                'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            ],
        ],
    ],
    'sms' => [
        'default' => env('SMS_DRIVER', 'log'),
        'from_number' => env('SMS_FROM', null),
        'drivers' => [
            'log' => [],
            'twilio' => [
                'sid' => env('TWILIO_SID', null),
                'token' => env('TWILIO_TOKEN', null),
            ],
        ],
    ],
    'templates' => [
        // Estimate templates
        'estimate.sent' => <<<'TEMPLATE'
Hello {{customer_name}},

Your estimate {{estimate_number}} is ready for review.

View your estimate: {{estimate_url}}

If you have any questions, please don't hesitate to contact us.

Thank you for your business!
TEMPLATE,
        'estimate.reminder' => <<<'TEMPLATE'
Hello {{customer_name}},

This is a friendly reminder that your estimate {{estimate_number}} is still awaiting your approval.

View your estimate: {{estimate_url}}

Please let us know if you have any questions or would like to proceed.

Thank you!
TEMPLATE,

        // Invoice templates
        'invoice.due' => <<<'TEMPLATE'
Hello {{customer_name}},

Invoice {{invoice_number}} for {{amount}} is due on {{due_date}}.

View and pay your invoice: {{invoice_url}}

Thank you for your business!
TEMPLATE,
        'invoice.paid' => <<<'TEMPLATE'
Hello {{customer_name}},

Thank you for your payment! Invoice {{invoice_number}} has been paid in full.

Amount paid: {{amount}}
Payment date: {{payment_date}}

View your invoice: {{invoice_url}}

We appreciate your business!
TEMPLATE,
        'invoice.overdue' => <<<'TEMPLATE'
Hello {{customer_name}},

This is a reminder that Invoice {{invoice_number}} for {{amount}} is now overdue.

Original due date: {{due_date}}
Days overdue: {{days_overdue}}

Please pay at your earliest convenience: {{invoice_url}}

If you have already made payment, please disregard this notice.

Thank you.
TEMPLATE,

        // Appointment templates
        'appointment.reminder' => <<<'TEMPLATE'
Hello {{customer_name}},

This is a reminder about your upcoming appointment:

Service: {{service_type}}
Date: {{appointment_date}}
Time: {{appointment_time}}

{{#notes}}
Notes: {{notes}}
{{/notes}}

If you need to reschedule or cancel, please contact us as soon as possible.

We look forward to seeing you!
TEMPLATE,
        'appointment.confirmed' => <<<'TEMPLATE'
Hello {{customer_name}},

Your appointment has been confirmed!

Service: {{service_type}}
Date: {{appointment_date}}
Time: {{appointment_time}}

We'll send you a reminder before your appointment.

Thank you for choosing us!
TEMPLATE,
        'appointment.cancelled' => <<<'TEMPLATE'
Hello {{customer_name}},

Your appointment scheduled for {{appointment_date}} at {{appointment_time}} has been cancelled.

If you did not request this cancellation or would like to reschedule, please contact us.

Thank you.
TEMPLATE,

        // Authentication templates
        'auth.password_reset' => <<<'TEMPLATE'
Hello,

You have requested to reset your password. Click the link below to set a new password:

{{reset_url}}

This link will expire in {{expiry_hours}} hours.

If you did not request this password reset, please ignore this email.
TEMPLATE,
        'auth.email_verification' => <<<'TEMPLATE'
Hello {{name}},

Please verify your email address by clicking the link below:

{{verification_url}}

This link will expire in {{expiry_hours}} hours.

If you did not create an account, please ignore this email.
TEMPLATE,
        'auth.welcome' => <<<'TEMPLATE'
Hello {{name}},

Welcome to our Auto Repair Shop Management System!

Your account has been created successfully. You can now log in to access your account.

Login: {{login_url}}

If you have any questions, please don't hesitate to contact us.

Thank you!
TEMPLATE,

        // Reminder campaign templates (dynamic body)
        'reminder.campaign' => '{{body}}',
        'reminder.campaign.sms' => '{{body}}',

        // Inventory templates
        'inventory.low_stock_alert' => <<<'TEMPLATE'
Low Stock Alert

{{total}} items are at or below their low stock threshold:

{{items_list}}

Please review and reorder as needed.
TEMPLATE,

        // Warranty templates
        'warranty.claim_submitted' => <<<'TEMPLATE'
Hello {{customer_name}},

Your warranty claim has been submitted successfully.

Claim ID: {{claim_id}}
Invoice: {{invoice_number}}
Status: {{status}}

We will review your claim and get back to you shortly.

Thank you.
TEMPLATE,
        'warranty.claim_updated' => <<<'TEMPLATE'
Hello {{customer_name}},

Your warranty claim {{claim_id}} has been updated.

New Status: {{status}}
{{#message}}
Message: {{message}}
{{/message}}

You can view the full details in your customer portal.

Thank you.
TEMPLATE,

        // Payment templates
        'payment.received' => <<<'TEMPLATE'
Hello {{customer_name}},

We have received your payment of {{amount}} for Invoice {{invoice_number}}.

Transaction ID: {{transaction_id}}
Payment Method: {{payment_method}}
Date: {{payment_date}}

Thank you for your payment!
TEMPLATE,
        'payment.refunded' => <<<'TEMPLATE'
Hello {{customer_name}},

A refund of {{amount}} has been processed for Invoice {{invoice_number}}.

Transaction ID: {{transaction_id}}
Refund Date: {{refund_date}}
{{#reason}}
Reason: {{reason}}
{{/reason}}

The refund should appear in your account within 5-10 business days.

Thank you.
TEMPLATE,
    ],
];
