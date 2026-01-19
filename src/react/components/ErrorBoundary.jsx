import { Component } from 'react'

function ExclamationTriangleIcon({ className }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
      />
    </svg>
  )
}

function DefaultFallback({ error, errorInfo, onReset }) {
  return (
    <div className="min-h-[400px] flex items-center justify-center p-6">
      <div className="max-w-md w-full bg-white rounded-lg shadow-lg border border-red-200 overflow-hidden">
        <div className="bg-red-50 px-6 py-4 border-b border-red-200">
          <div className="flex items-center">
            <ExclamationTriangleIcon className="h-6 w-6 text-red-500 flex-shrink-0" />
            <h2 className="ml-3 text-lg font-semibold text-red-800">Something went wrong</h2>
          </div>
        </div>

        <div className="px-6 py-4">
          <p className="text-gray-600 text-sm mb-4">
            An unexpected error occurred. The error has been logged and we'll look into it.
          </p>

          {error && (
            <div className="bg-gray-50 rounded-md p-3 mb-4">
              <p className="text-sm font-medium text-gray-700 mb-1">Error details:</p>
              <p className="text-sm text-red-600 font-mono break-words">{error.message}</p>
            </div>
          )}

          {process.env.NODE_ENV === 'development' && errorInfo && (
            <details className="mb-4">
              <summary className="text-sm text-gray-500 cursor-pointer hover:text-gray-700">
                Component stack trace
              </summary>
              <pre className="mt-2 text-xs text-gray-600 bg-gray-50 p-3 rounded-md overflow-auto max-h-48">
                {errorInfo.componentStack}
              </pre>
            </details>
          )}

          <div className="flex gap-3">
            <button
              type="button"
              onClick={onReset}
              className="flex-1 inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors"
            >
              Try again
            </button>
            <button
              type="button"
              onClick={() => window.location.reload()}
              className="flex-1 inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors"
            >
              Reload page
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = {
      hasError: false,
      error: null,
      errorInfo: null,
    }
  }

  static getDerivedStateFromError(error) {
    // Update state so the next render shows the fallback UI
    return { hasError: true, error }
  }

  componentDidCatch(error, errorInfo) {
    // Log the error information
    this.setState({ errorInfo })

    // Log to console for debugging
    console.error('ErrorBoundary caught an error:', error)
    console.error('Component stack:', errorInfo.componentStack)

    // Call optional onError callback if provided
    if (this.props.onError) {
      this.props.onError(error, errorInfo)
    }
  }

  handleReset = () => {
    this.setState({
      hasError: false,
      error: null,
      errorInfo: null,
    })

    // Call optional onReset callback if provided
    if (this.props.onReset) {
      this.props.onReset()
    }
  }

  render() {
    const { hasError, error, errorInfo } = this.state
    const { children, fallback } = this.props

    if (hasError) {
      // If a custom fallback is provided, use it
      if (fallback) {
        // If fallback is a function, call it with error info and reset handler
        if (typeof fallback === 'function') {
          return fallback({ error, errorInfo, onReset: this.handleReset })
        }
        // Otherwise render the fallback element directly
        return fallback
      }

      // Use default fallback UI
      return <DefaultFallback error={error} errorInfo={errorInfo} onReset={this.handleReset} />
    }

    return children
  }
}
