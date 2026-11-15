# Deploy on Railway

This image contains only the PHP/Apache application. It does not create or
initialize a database. Railway's MySQL service remains separate, and its
database data persists independently of application image rebuilds.

1. Deploy this repository as a Railway application service. Railway will build
   the root `Dockerfile`.
2. In the application service's Variables, map the Railway MySQL service
   variables:

   ```text
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_NAME=${{MySQL.MYSQLDATABASE}}
   DB_USER=${{MySQL.MYSQLUSER}}
   DB_PASS=${{MySQL.MYSQLPASSWORD}}
   ```

   Replace `MySQL` with the exact name of your Railway database service.
   Alternatively, the app can read Railway's `MYSQLHOST`, `MYSQLPORT`,
   `MYSQLDATABASE`, `MYSQLUSER`, and `MYSQLPASSWORD` variables directly.
3. Ensure the database already has this application's schema. The image does
   not run `database/schema.sql`, because that script drops and recreates the
   database.

Apache listens on Railway's `PORT` environment variable (8080 if unset). The
image does not include local `.env` files or local manuscript PDFs.
