# PHP-Web-Server

PHP is a popular general scripting programming language geared towards development.

## Deployment environment

The application now reads deployment-specific paths and URLs from environment variables instead of hardcoded runtime values.
Use the included `.env.example` as a reference for the variables your deployment should provide:

- `LUM_APP_ROOT` - absolute application root used for include/path resolution
- `LUM_APP_URL` - base application URL used for redirects and generated links
- `LUM_LOGIN_PATH` - login route appended to `LUM_APP_URL`
- `LUM_DB_CONFIG` - absolute path to the database INI file outside the web root

## Validation

Run `scripts/validate-repo.sh` to check PHP syntax, detect unexpected deployment-specific hardcoding, and verify that bootstrap path resolution rejects traversal input.

Intentional retained production references:

- `bootstrap.php` keeps production-style fallback defaults for `LUM_APP_URL` and `LUM_DB_CONFIG` when the environment is not set.
