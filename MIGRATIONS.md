# Migraciones de Base de Datos

**Solo necesarias si ya tienes una instalación en marcha.**

Para instalaciones nuevas, ejecuta `supabase-schema.sql` que ya incluye todas las migraciones consolidadas.

## Orden de aplicación

Ejecutar en **Supabase → SQL Editor** en este orden (todas son seguras de re-ejecutar):

| # | Archivo | Descripción |
|---|---------|-------------|
| 1 | `supabase-migration-precios.sql` | Precios, categorías y servicios por local |
| 2 | `supabase-migration-duraciones.sql` | Actualiza duración de servicios (Barba → 20 min) |
| 3 | `supabase-migration-quitar-depilacion.sql` | Elimina servicios de depilación |
| 4 | `supabase-migration-cancelaciones.sql` | Agrega columnas de evento de Google en `appointments` |
| 5 | `supabase-migration-gestion.sql` | Token de gestión por cita + índice |
| 6 | `supabase-migration-sync-calendarios.sql` | Columna `google_events` para sincronización |
| 7 | `supabase-migration-graciela-solo-malaga.sql` | Restricción de disponibilidad por local |
| 8 | `supabase-migration-antisolapamiento.sql` | Impide dos citas solapadas del mismo barbero |
| 9 | `supabase-migration-corte-nino-12.sql` | Renombra "Corte Niño" a "Corte Niño hasta 12 años" |

## Cómo aplicar

1. Copia el contenido de cada archivo `.sql`
2. Ve a tu proyecto en Supabase
3. Abre **SQL Editor** → **New Query**
4. Pega el contenido y ejecuta
5. Verifica que no hay errores

## Rollback

Si una migración falla:
- Revisa el mensaje de error en Supabase
- Consulta el archivo `.sql` para entender qué intentaba cambiar
- Puedes re-ejecutar si es segura de re-ejecutar (ver tabla arriba)
- Contacta al dev si hay un error inesperado
