<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Ubuntu 24.04 Deployment (Docker + main-service)

This project expects PostgreSQL and Redis from `main-service` on shared Docker network `dev-network`.

### 1) Run bootstrap from local machine

```bash
ssh edualima-server 'bash -s' < scripts/setup-ubuntu-24.04.sh
```

If you want the script to clone repositories automatically, pass variables:

```bash
MAIN_SERVICE_REPO='git@github.com:YOUR_ORG/main-service.git' \
MUGHNI_REPO='git@github.com:YOUR_ORG/mughni-crypto.git' \
DEPLOY_BRANCH='main' \
ssh edualima-server 'MAIN_SERVICE_REPO="'"$MAIN_SERVICE_REPO"'" MUGHNI_REPO="'"$MUGHNI_REPO"'" DEPLOY_BRANCH="'"$DEPLOY_BRANCH"'" bash -s' < scripts/setup-ubuntu-24.04.sh
```

### 2) Required environment variables for webhook deploy

Add these values in `.env` (inside `mughni-crypto`):

```dotenv
GITHUB_WEBHOOK_SECRET=replace_with_random_secret
GITHUB_DEPLOY_BRANCH=main
GITHUB_DEPLOY_REPOSITORY=OWNER/REPO
GITHUB_DEPLOY_QUEUE=default
GITHUB_DEPLOY_SCRIPT_PATH=/var/www/html/scripts/deploy-from-webhook.sh
GITHUB_DEPLOY_SCRIPT_TIMEOUT=600
```

### 3) GitHub webhook endpoint

- URL: `https://YOUR_TUNNEL_DOMAIN/webhook/github`
- Content type: `application/json`
- Secret: same as `GITHUB_WEBHOOK_SECRET`
- Event: `push`

The app verifies:
- `X-Hub-Signature-256`
- Push branch matches `GITHUB_DEPLOY_BRANCH`
- Repository matches `GITHUB_DEPLOY_REPOSITORY` (if set)

### 4) Manual deploy script execution in app container

```bash
docker exec -it al_mughni bash /var/www/html/scripts/deploy-from-webhook.sh
```

Deployment logs:
- Laravel job logs: `storage/logs/deployment.log`
- Script logs: `storage/logs/deploy-script.log`

## Cloudflare Tunnel (`mughni-crypto.web.id`)

This repository now includes an optional `cloudflared` service in Docker Compose. It is disabled by default and only starts when you run the Cloudflare profile.

### 1) Set the application URL in `.env`

Use your public hostname so Laravel generates secure links and cookies correctly:

```dotenv
APP_URL=https://mughni-crypto.web.id
SESSION_DOMAIN=mughni-crypto.web.id
SESSION_SECURE_COOKIE=true
CLOUDFLARE_TUNNEL_TOKEN=replace_with_your_cloudflare_tunnel_token
```

### 2) Create the tunnel in Cloudflare Zero Trust

Create a remotely managed tunnel in Cloudflare and add a public hostname:

- Hostname: `mughni-crypto.web.id`
- Service type: `HTTP`
- URL / origin service: `http://nginx:80`

Cloudflare will give you a tunnel token. Put that token in `CLOUDFLARE_TUNNEL_TOKEN`.

### 3) Start the app and tunnel

```bash
make up
```

To inspect the tunnel logs:

```bash
make tunnel-logs
```

To stop only the tunnel:

```bash
make tunnel-down
```

`make up` now rebuilds the application image and re-syncs the public assets used by Nginx, so code changes are picked up automatically without a manual `docker cp`.

### 4) Lock down direct origin access

Cloudflare Tunnel does not need inbound access to your server's published web ports. Restrict direct access to the host ports with your firewall or reverse-proxy rules so traffic reaches the app through Cloudflare instead of bypassing it.

### 5) Webhook URL for this domain

After the tunnel is active, your GitHub webhook endpoint becomes:

```text
https://mughni-crypto.web.id/webhook/github
```
