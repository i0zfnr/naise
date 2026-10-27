# Naise Coffee v3

A responsive installable PWA that closely recreates the public Naise Coffee menu and works as a web view on desktop and mobile. Laravel 13 serves the application and its JSON API.

## Run locally

```powershell
cd backend/laravel
npm install
npm run build
php artisan serve
```

Use `npm run dev` from `backend/laravel` while developing. On mobile, open the Laravel development server from a device on the same network to preview its responsive layout. The manifest and service worker are served from Laravel's `public/` folder.

## API

- `GET /api/health`
- `GET /api/menu?category=Coffee&search=latte&sort=asc`
- `POST /api/orders` (cart intake stub; pickup and payment provider are not connected)

The catalog and displayed product images/prices were captured from the publicly accessible Naise menu on 2026-10-27. Original product and profile routes redirect to Google sign-in; their authenticated customization and rewards details remain to be verified. The `.rea-reference` directory contains the CC0-licensed reference list consulted during the clean-room rebuild and is not an application dependency.
