# PHP-Web-Server

## Environment configuration

The application keeps its production defaults, but deployments can override these values with environment variables:

- `LUM_APP_ROOT` - application root path. Fallback: `/var/www/Lynx`
- `LUM_APP_URL` - base application URL used for redirects and asset URLs. Fallback: `https://lynx-um.co.za`
- `LUM_DB_CONFIG` - path to the external database INI file. Fallback: `/var/secure_configs/lynx_db.ini`

`LUM_APP_URL` is normalized to remove any trailing slash before use. Keep secrets in the external database config file rather than committing them to this repository.
