/**
 * FixItForUs CMS Admin JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initTabs();
    initModals();
    initForms();
    initTables();
    initSidebar();
    initAlerts();
});

/**
 * Tabs functionality
 */
function initTabs() {
    const tabContainers = document.querySelectorAll('.tabs');

    tabContainers.forEach(container => {
        const tabs = container.querySelectorAll('.tab');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                const parent = tab.closest('.card') || document;

                // Remove active from all tabs and content
                container.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                parent.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

                // Add active to clicked tab and target content
                tab.classList.add('active');
                const targetContent = parent.querySelector(`#${target}`);
                if (targetContent) {
                    targetContent.classList.add('active');
                }
            });
        });
    });
}

/**
 * Modal functionality
 */
function initModals() {
    // Open modal triggers
    document.querySelectorAll('[data-modal]').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            const modalId = trigger.dataset.modal;
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
            }
        });
    });

    // Close modal buttons
    document.querySelectorAll('.modal-close, [data-modal-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal-backdrop');
            if (modal) {
                modal.classList.remove('active');
            }
        });
    });

    // Close on backdrop click
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) {
                backdrop.classList.remove('active');
            }
        });
    });

    // Close on escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.active').forEach(modal => {
                modal.classList.remove('active');
            });
        }
    });
}

/**
 * Form enhancements
 */
function initForms() {
    // Auto-generate slug from title/name
    document.querySelectorAll('[data-slug-source]').forEach(input => {
        const sourceId = input.dataset.slugSource;
        const sourceInput = document.getElementById(sourceId);

        if (sourceInput) {
            sourceInput.addEventListener('input', () => {
                if (!input.dataset.edited) {
                    input.value = slugify(sourceInput.value);
                }
            });

            input.addEventListener('input', () => {
                input.dataset.edited = 'true';
            });
        }
    });

    // Confirm delete
    document.querySelectorAll('[data-confirm]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const message = btn.dataset.confirm || 'Are you sure you want to delete this item?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // Form validation
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', (e) => {
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            });

            if (!valid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    });
}

/**
 * Table enhancements
 */
function initTables() {
    // Select all checkbox
    document.querySelectorAll('.select-all').forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            const table = checkbox.closest('table');
            const checkboxes = table.querySelectorAll('tbody input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
        });
    });

    // Row click to select
    document.querySelectorAll('.table-selectable tbody tr').forEach(row => {
        row.addEventListener('click', (e) => {
            if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A') {
                const checkbox = row.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                }
            }
        });
    });
}

/**
 * Mobile sidebar toggle
 */
function initSidebar() {
    const toggle = document.getElementById('sidebar-toggle');
    const sidebar = document.querySelector('.admin-sidebar');

    if (toggle && sidebar) {
        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
    }
}

/**
 * Auto-dismiss alerts
 */
function initAlerts() {
    document.querySelectorAll('.alert[data-dismiss]').forEach(alert => {
        const delay = parseInt(alert.dataset.dismiss) || 5000;
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, delay);
    });

    // Manual dismiss
    document.querySelectorAll('.alert .close').forEach(btn => {
        btn.addEventListener('click', () => {
            const alert = btn.closest('.alert');
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    });
}

/**
 * Utility: Convert string to slug
 */
function slugify(text) {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^\w\s-]/g, '')
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * Utility: Show toast notification
 */
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container') || createToastContainer();

    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.textContent = message;
    toast.style.cssText = 'animation: slideIn 0.3s ease; margin-bottom: 0.5rem;';

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toast-container';
    container.style.cssText = 'position: fixed; top: 1rem; right: 1rem; z-index: 1000; max-width: 400px;';
    document.body.appendChild(container);
    return container;
}

/**
 * AJAX form submission
 */
function submitFormAjax(form, callback) {
    const formData = new FormData(form);

    fetch(form.action, {
        method: form.method || 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (callback) callback(data);
        if (data.message) {
            showToast(data.message, data.success ? 'success' : 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred. Please try again.', 'error');
    });
}

/**
 * Preview component/page in new window
 */
function previewPage(url) {
    window.open(url, '_blank', 'width=1200,height=800');
}

/**
 * Copy text to clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Copied to clipboard!', 'success');
    }).catch(() => {
        showToast('Failed to copy', 'error');
    });
}

/**
 * Clear cache via AJAX
 */
function clearCache(type = 'all') {
    if (!confirm('Are you sure you want to clear the cache?')) {
        return;
    }

    fetch('/admin/cache/clear', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('[name="csrf_token"]')?.value
        },
        body: JSON.stringify({ type })
    })
    .then(response => response.json())
    .then(data => {
        showToast(data.message || 'Cache cleared successfully', 'success');
    })
    .catch(() => {
        showToast('Failed to clear cache', 'error');
    });
}
