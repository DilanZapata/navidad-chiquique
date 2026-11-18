# Registro Fiesta de Fin de Año · Agropecuaria Chiquique

PHP 8.1+ con SQLite (sin instalar base de datos). La base se crea sola en `data/fiesta.sqlite`.

## Usar con XAMPP
Copia esta carpeta a `htdocs/` y abre `http://localhost/fiesta-chiquique/`.
La carpeta `data/` debe tener permiso de escritura para Apache.

## Usar sin XAMPP
    php -S localhost:8080 router.php

## Panel de administración
`/admin/` — la primera vez te pide crear el usuario y la contraseña.

- **Empleados**: registrar, editar, desactivar, eliminar, importar CSV (`cedula;nombre`, ver `ejemplo_empleados.csv`).
- **Asistentes**: buscar y filtrar por tipo, sexo, área, rango de edad o "menores de N"; exportar a Excel/CSV lo filtrado.
- **Configuración**: fecha, lugar, fecha límite, máximo de acompañantes, edad límite de niño.

Copia de seguridad: guarda el archivo `data/fiesta.sqlite`.

## Desplegar en Dokploy
1. Sube esta carpeta (`fiesta-chiquique`) a un repositorio Git.
2. En Dokploy crea una **Application**, conéctala al repositorio y elige **Build Type: Dockerfile**.
3. En **Advanced → Volumes/Mounts** agrega un *Volume Mount* con ruta en el contenedor `/data`.
   Ahí queda `fiesta.sqlite`; sin ese volumen los registros se pierden en cada despliegue.
4. En **Domains** asigna el dominio, puerto del contenedor `80`, y activa HTTPS.
5. Despliega y entra a `/admin/` para crear el usuario administrador.
