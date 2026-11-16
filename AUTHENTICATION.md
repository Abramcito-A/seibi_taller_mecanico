# Acceso interno de SEIBI

SEIBI utiliza el guard web de sesiones de Laravel. El acceso es exclusivo para cuentas internas; no hay registro web ni restablecimiento público de contraseñas. Las sesiones caducan tras 120 minutos de inactividad (`SESSION_LIFETIME`).

## Preparar roles y crear cuentas

En una instalación nueva, ejecuta las migraciones y crea los roles:

```powershell
php artisan migrate
php artisan db:seed --class=RoleSeeder
```

Para crear el primer administrador o cualquier otra cuenta, ejecuta el comando desde la raíz del proyecto y responde a sus preguntas:

```powershell
php artisan seibi:user:create
```

El comando solicita nombre, correo, rol existente y contraseña oculta dos veces. La contraseña debe tener al menos 12 caracteres, mayúsculas, minúsculas, números y símbolos. Nunca imprime la contraseña. Para crear una cuenta inicialmente inactiva:

```powershell
php artisan seibi:user:create --inactive
```

`php artisan db:seed` también ejecuta `RoleSeeder`, pero no crea usuarios. Las cuentas se asignan explícitamente con `users.id_rol`; el formulario público no puede elegir roles ni cambiar el estado de una cuenta.

## Producción

Despliega únicamente detrás de HTTPS y configura `SESSION_SECURE_COOKIE=true` en el entorno de producción. En desarrollo HTTP local, deja la variable sin establecer o en `false`; `config/session.php` activa la opción por defecto cuando `APP_ENV=production` y conserva `http_only=true` y `same_site=lax`. No guardes estas variables en el repositorio ni copies secretos a `.env.example`.

La tabla de sesiones debe existir si `SESSION_DRIVER=database`. La aplicación conserva el driver configurado por el proyecto y no modifica `.env`.