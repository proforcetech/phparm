# Contributing to PHPArm

First off, thank you for considering contributing to PHPArm! It's people like you that make PHPArm such a great tool for auto repair shops.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Commit Message Guidelines](#commit-message-guidelines)
- [Pull Request Process](#pull-request-process)
- [Testing Guidelines](#testing-guidelines)
- [Documentation](#documentation)

## Code of Conduct

This project and everyone participating in it is governed by our Code of Conduct. By participating, you are expected to uphold this code. Please report unacceptable behavior to support@phparm.dev.

### Our Pledge

We pledge to make participation in our project a harassment-free experience for everyone, regardless of age, body size, disability, ethnicity, gender identity and expression, level of experience, nationality, personal appearance, race, religion, or sexual identity and orientation.

### Our Standards

**Positive behavior includes:**
- Using welcoming and inclusive language
- Being respectful of differing viewpoints and experiences
- Gracefully accepting constructive criticism
- Focusing on what is best for the community
- Showing empathy towards other community members

**Unacceptable behavior includes:**
- Trolling, insulting/derogatory comments, and personal or political attacks
- Public or private harassment
- Publishing others' private information without explicit permission
- Other conduct which could reasonably be considered inappropriate in a professional setting

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check existing issues to avoid duplicates. When creating a bug report, include as many details as possible:

**Use this template:**

```markdown
**Describe the bug**
A clear and concise description of what the bug is.

**To Reproduce**
Steps to reproduce the behavior:
1. Go to '...'
2. Click on '....'
3. Scroll down to '....'
4. See error

**Expected behavior**
A clear and concise description of what you expected to happen.

**Screenshots**
If applicable, add screenshots to help explain your problem.

**Environment:**
 - OS: [e.g. Ubuntu 22.04]
 - PHP Version: [e.g. 8.1]
 - Browser: [e.g. Chrome 120]
 - Node Version: [e.g. 18.0]

**Additional context**
Add any other context about the problem here.
```

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, include:

- **Clear title** and **detailed description**
- **Use cases** - explain how this would benefit users
- **Possible implementation** - if you have ideas about how to implement
- **Screenshots or mockups** - if applicable

### Your First Code Contribution

Unsure where to begin? Look for issues labeled:

- `good first issue` - Good for newcomers
- `help wanted` - Extra attention needed
- `documentation` - Documentation improvements

### Pull Requests

1. **Fork the repo** and create your branch from `main`
2. **Make your changes** following our coding standards
3. **Add tests** if you've added functionality
4. **Update documentation** for any changed functionality
5. **Ensure tests pass** - run full test suite
6. **Create the pull request**

## Development Setup

### Prerequisites

- PHP >= 8.0
- Composer >= 2.0
- Node.js >= 18.0
- MySQL >= 5.7 or MariaDB >= 10.3
- Git

### Setup Steps

1. **Fork and clone the repository:**

```bash
git clone https://github.com/YOUR_USERNAME/phparm.git
cd phparm
```

2. **Install dependencies:**

```bash
# PHP dependencies
composer install

# Node dependencies
npm install
```

3. **Setup environment:**

```bash
cp .env.example .env
# Edit .env with your local configuration
```

4. **Setup database:**

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE phparm_dev"

# Import schema
mysql -u root -p phparm_dev < database/schema.sql

# Import test data
mysql -u root -p phparm_dev < database/seed_data.sql
```

5. **Generate JWT secret:**

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
# Copy output to JWT_SECRET in .env
```

6. **Start development servers:**

```bash
# Terminal 1: PHP backend
php -S localhost:8000 -t public

# Terminal 2: Frontend dev server
npm run dev
```

7. **Access the application:**

- Frontend: http://localhost:3000
- Backend API: http://localhost:8000/api

## Coding Standards

### PHP Standards

We follow **PSR-12** coding standards for PHP.

**Key points:**
- Use 4 spaces for indentation (no tabs)
- Opening braces on same line for classes, methods
- One blank line after namespace declaration
- Visibility must be declared on all properties and methods
- Type hints and return types where possible

**Example:**

```php
<?php

namespace App\Controllers;

use App\Models\Customer;

class CustomerController
{
    private CustomerService $service;

    public function __construct(CustomerService $service)
    {
        $this->service = $service;
    }

    public function index(User $user, array $params): array
    {
        $customers = $this->service->getAll($params);

        return [
            'success' => true,
            'data' => $customers,
        ];
    }
}
```

**Check code style:**

```bash
composer run phpcs
```

**Auto-fix code style:**

```bash
composer run phpcbf
```

### JavaScript/Vue Standards

We use **ESLint** with Vue plugin and Prettier.

**Key points:**
- Use 2 spaces for indentation
- Use composition API for Vue components
- Use `const` for immutable variables, `let` for mutable
- Use arrow functions where appropriate
- Destructure props when possible

**Example Vue component:**

```vue
<template>
  <div class="container">
    <h1>{{ title }}</h1>
    <Button @click="handleClick">
      Click Me
    </Button>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import Button from '@/components/ui/Button.vue'

const props = defineProps({
  title: {
    type: String,
    required: true,
  },
})

const emit = defineEmits(['submit'])

const count = ref(0)

const doubleCount = computed(() => count.value * 2)

function handleClick() {
  count.value++
  emit('submit', count.value)
}
</script>
```

**Check code style:**

```bash
npm run lint
```

**Auto-fix code style:**

```bash
npm run lint:fix
```

### CSS/Tailwind Standards

- Use Tailwind utility classes when possible
- Custom CSS only when necessary
- Follow mobile-first responsive design
- Use Tailwind's spacing scale consistently

**Example:**

```vue
<!-- Good -->
<div class="flex items-center justify-between p-4 bg-white rounded-lg shadow">
  <h2 class="text-lg font-semibold text-gray-900">Title</h2>
  <Button>Action</Button>
</div>

<!-- Avoid custom CSS -->
<div style="display: flex; padding: 1rem; background: white;">
  <!-- ... -->
</div>
```

## Commit Message Guidelines

We follow the **Conventional Commits** specification.

### Format

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Types

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, no logic change)
- `refactor`: Code refactoring
- `perf`: Performance improvements
- `test`: Adding or updating tests
- `chore`: Build process or auxiliary tool changes
- `ci`: CI/CD changes

### Examples

```bash
# Feature
feat(invoice): add PDF download button

# Bug fix
fix(auth): resolve token expiration issue

# Documentation
docs(readme): update installation instructions

# Multiple changes
feat(payment): add Square payment gateway integration

Added Square payment gateway support with:
- Payment processing
- Webhook handling
- Refund functionality

Closes #123
```

### Scope

The scope should be the name of the affected component/module:

- `auth`
- `invoice`
- `customer`
- `vehicle`
- `appointment`
- `payment`
- `inventory`
- `ui`
- `api`

## Pull Request Process

### Before Creating PR

1. **Update your fork:**

```bash
git checkout main
git pull upstream main
git checkout your-feature-branch
git rebase main
```

2. **Run tests:**

```bash
composer test
npm test
```

3. **Check code style:**

```bash
composer run phpcs
npm run lint
```

4. **Update documentation** if needed

### Creating the PR

1. **Push to your fork:**

```bash
git push origin your-feature-branch
```

2. **Create PR on GitHub** with clear title and description

3. **PR Template:**

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] All tests pass
- [ ] New tests added (if applicable)
- [ ] Manual testing completed

## Checklist
- [ ] Code follows style guidelines
- [ ] Self-review completed
- [ ] Comments added for complex code
- [ ] Documentation updated
- [ ] No new warnings generated
- [ ] Tests added/updated

## Related Issues
Closes #123

## Screenshots (if applicable)
Add screenshots here
```

### PR Review Process

1. At least **one reviewer** must approve
2. All **automated checks** must pass
3. **Resolve all comments** from reviewers
4. **Update PR** based on feedback
5. **Squash commits** if requested

### After PR Approval

Your PR will be merged by a maintainer. The branch will be automatically deleted.

## Testing Guidelines

### Backend Tests

**Location:** `tests/`

**Running tests:**

```bash
# All tests
composer test

# Specific test
php tests/test-vin-decoder.php
```

**Writing tests:**

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Vehicle\VinDecoderService;

// Test VIN validation
$service = new VinDecoderService();
$isValid = $service->isValidFormat('1HGBH41JXMN109186');

assert($isValid === true, 'Valid VIN should pass');

echo "✓ VIN validation test passed\n";
```

### Frontend Tests

**Location:** `src/__tests__/`

**Running tests:**

```bash
# All tests
npm test

# Watch mode
npm test:watch

# Coverage
npm test:coverage
```

**Writing tests:**

```javascript
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import Button from '@/components/ui/Button.vue'

describe('Button', () => {
  it('renders properly', () => {
    const wrapper = mount(Button, {
      props: { variant: 'primary' },
      slots: { default: 'Click Me' },
    })
    expect(wrapper.text()).toContain('Click Me')
  })

  it('emits click event', async () => {
    const wrapper = mount(Button)
    await wrapper.trigger('click')
    expect(wrapper.emitted()).toHaveProperty('click')
  })
})
```

### Test Coverage

We aim for:
- **Backend:** 80% code coverage
- **Frontend:** 70% code coverage
- **Critical paths:** 100% coverage (auth, payments)

## Documentation

### Code Documentation

**PHP DocBlocks:**

```php
/**
 * Get customer by ID
 *
 * @param int $id Customer ID
 * @return array Customer data
 * @throws NotFoundException If customer not found
 */
public function getById(int $id): array
{
    // Implementation
}
```

**JavaScript JSDoc:**

```javascript
/**
 * Format currency value
 * @param {number} amount - Amount to format
 * @param {string} currency - Currency code (default: USD)
 * @returns {string} Formatted currency string
 */
function formatCurrency(amount, currency = 'USD') {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
  }).format(amount)
}
```

### API Documentation

Update `docs/API.md` when adding/modifying endpoints.

**Format:**

```markdown
### Get Customer

**Endpoint:** `GET /api/customers/{id}`

**Authentication:** Required

**Parameters:**
- `id` (integer, required) - Customer ID

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

**Error Codes:**
- `404` - Customer not found
- `401` - Unauthorized
```

### README Updates

Update README.md when:
- Adding new features
- Changing installation process
- Updating dependencies
- Adding configuration options

## Recognition

Contributors will be:
- Listed in CONTRIBUTORS.md
- Mentioned in release notes
- Eligible for "Top Contributor" badge

## Questions?

- **GitHub Discussions:** Ask questions and discuss ideas
- **Issues:** Report bugs or request features
- **Email:** support@phparm.dev

---

Thank you for contributing to PHPArm! 🚗✨
