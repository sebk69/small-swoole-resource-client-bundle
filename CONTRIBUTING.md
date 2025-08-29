# Contributing Guide

Thanks for considering a contribution! This project follows modern PHP practices and aims to keep
the codebase small, readable, and well-tested.

## Table of Contents
- [Development Setup](#development-setup)
- [Architecture](#architecture)
- [Coding Standards](#coding-standards)
- [Static Analysis](#static-analysis)
- [Testing](#testing)
- [Commit Messages](#commit-messages)
- [Pull Requests](#pull-requests)
- [Security](#security)
- [License](#license)

## Development Setup

1. Fork & clone the repository.
2. Install dependencies:
   ```bash
   composer install
   ```
3. Make sure you can run the test suite:
   ```bash
   vendor/bin/pest
   ```

## Architecture

- Namespace root: `Small\SwooleResourceClientBundle`
- Key components:
  - `Resource\Factory` — builds an `HttpClientInterface`, configures it, and returns `Resource` instances.
  - `Resource\Resource` — minimal client for a server-side resource (get/lock/write/unlock).

- The bundle takes `server_uri` and `api_key` from configuration and sets them on the HTTP client.

## Coding Standards

- PHP 8.2+ syntax (typed properties, constructor property promotion, attributes if needed).
- Follow **PSR-12** for style and **PSR-4** for autoloading.
- Keep classes `final` unless extension is necessary.
- Prefer immutability and pure functions where possible.

We recommend `friendsofphp/php-cs-fixer` or equivalent locally. Style checks may run in CI.

## Static Analysis

We use **PHPStan**. Run:
```bash
vendor/bin/phpstan analyse
```
Target the strictest level your local environment supports.

## Testing

- The project uses **Pest**.
- Unit tests should isolate behavior with fakes/doubles.
- Feature tests can boot the Symfony Kernel and read services from the test container.

Common commands:
```bash
vendor/bin/pest
vendor/bin/pest --filter Resource
```

If you add public APIs (methods/classes), please add/adjust tests accordingly.

## Commit Messages

Use clear, imperative commit messages, e.g.:
```
fix(resource): unlockData throws on non-200
feat(factory): allow injecting HttpClientInterface
docs: add feature-test instructions
```

We loosely follow **Conventional Commits** (`feat:`, `fix:`, `docs:`, `refactor:`, etc.).

## Pull Requests

- One logical change per PR.
- Include tests for new/changed behavior.
- Update README when adding features.
- Ensure `composer.json` stays valid and autoload maps are correct.
- CI must be green.

## Security

If you discover a security issue, **do not** open a public issue.
Please contact the maintainer privately to coordinate a fix and release.

## License

By contributing, you agree that your contributions will be licensed under the **MIT** license.
