# esoTalk 1.0.0

**License:** GNU Affero General Public License v3.0 (AGPLv3) — see `LICENSE`

Línea clásica de esoTalk (`IN_ESO` / mysqli) modernizada para **PHP 8.0–8.4**.

Proyecto: **https://github.com/Scoppettuolo**

Blog / info: **https://katnya.blogspot.com/**

> Esta es la base **1.x** (arquitectura antigua).  
> La línea **2.0.0** es el código g4/g5.

## Requisitos
- PHP ≥ 8.0 con **PDO SQLite** habilitado para la instalación predeterminada
- **mysqli** para instalaciones alternativas con MySQL 5.7+ / MariaDB 10.3+

## Instalación
1. Copia a `htdocs/esoTalk1` (o similar)
2. Abre `/install/` en el navegador
3. Elige el idioma y el controlador en el instalador. **SQLite** se usa por defecto; también puedes seleccionar MySQL/MariaDB mediante **mysqli**.
4. Si eliges MySQL/MariaDB

## Cambios de modernización
- Login: verificación de contraseña correcta + **upgrade automático** de hashes md5 → bcrypt
- `verifyPassword()` acepta bcrypt, argon y md5 legado
- SQLite mediante PDO como motor predeterminado; MySQL/MariaDB mediante mysqli con **InnoDB** y utf8mb4
- Cookies de sesión: HttpOnly + SameSite=Lax + Secure si hay HTTPS
- `#[AllowDynamicProperties]` para PHP 8.2+
- `.htaccess` de protección básica
