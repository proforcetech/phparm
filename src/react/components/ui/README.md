# React UI component parity notes

## Most reused Vue UI components (by import count)

Counts based on import references in `src/` for `src/components/ui/*.vue`:

| Component | Import count | React equivalent |
| --- | --- | --- |
| `Button.vue` | 114 | `Button.jsx` |
| `Card.vue` | 114 | `Card.jsx` |
| `Input.vue` | 77 | `Input.jsx` |
| `Badge.vue` | 63 | `Badge.jsx` |
| `Loading.vue` | 59 | `Loading.jsx` |
| `Alert.vue` | 51 | `Alert.jsx` |

## API differences to track during migration

### `Button`
- Vue emits `click`; React uses the native `onClick` prop.

### `Card`
- Vue uses named slots (`header`, `footer`); React uses `header` and `footer` props that accept React nodes.
- Body content is still `children`.

### `Input`
- Vue uses `modelValue` + `update:modelValue`; React supports `modelValue` + `onUpdateModelValue` **and** `value` + `onChange`.
- Vue emits `validation-error`; React uses `onValidationError`.
- `icon` accepts a React component or element instead of a Vue component.
- Default `id` uses React `useId()` instead of a randomized string.

### `Badge`
- React renders a dot indicator element when `dot` is `true`; Vue only added spacing (no dot element).

### `Loading`
- No prop differences; React uses `children`-less usage like Vue.

### `Alert`
- Vue uses `modelValue` with `update:modelValue`; React uses `modelValue` with `onUpdateModelValue` and `onClose`.
- Vue slots map to React `children` (fallback to `message`).
