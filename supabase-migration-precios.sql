-- ============================================================
-- Six40 Booking — Migración v3: precios + categorías + servicios por local
-- Ejecutar en: Supabase → SQL Editor
-- Seguro de re-ejecutar. Recarga la tabla de servicios desde cero.
-- ============================================================

-- 1. Nuevas columnas en services
ALTER TABLE public.services ADD COLUMN IF NOT EXISTS price       NUMERIC(6,2);
ALTER TABLE public.services ADD COLUMN IF NOT EXISTS price_from  BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE public.services ADD COLUMN IF NOT EXISTS category    TEXT;

-- 2. Permitir el mismo nombre en distintos locales (antes name era UNIQUE global)
ALTER TABLE public.services DROP CONSTRAINT IF EXISTS services_name_key;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_service_name_location
  ON public.services (name, COALESCE(location, ''));

-- 3. Limpiar servicios anteriores (y sus referencias de prueba)
DELETE FROM public.appointment_services;
DELETE FROM public.services;

-- 4. Recargar servicios POR LOCAL con precios y categorías
--    category: 'corte' | 'barba' | 'tratamiento'
--    type se mantiene por compatibilidad (base/additional) pero el formulario
--    usa 'menú libre': el cliente elige cualquier combinación (mínimo 1).
INSERT INTO public.services (id, name, duration, type, category, location, price, price_from, display_order) VALUES
  -- ── MÁLAGA ────────────────────────────────────────────────
  -- Corte
  (1,  'Corte',                     30, 'base',       'corte',        'malaga', 15.00, false, 1),
  (2,  'Corte Niño hasta 12 años',  30, 'base',       'corte',        'malaga', 12.50, false, 2),
  (3,  'Rapado',                    20, 'base',       'corte',        'malaga', 10.00, false, 3),
  -- Barba
  (4,  'Arreglo de barba a máquina',15, 'base',       'barba',        'malaga',  8.00, false, 10),
  (5,  'Arreglo de barba a navaja', 20, 'base',       'barba',        'malaga', 10.00, false, 11),
  (6,  'Afeitado',                  30, 'base',       'barba',        'malaga', 12.00, false, 12),
  -- Tratamientos
  (10, 'Color barba',               20, 'additional', 'tratamiento',  'malaga',  8.00, false, 30),
  (11, 'Reducción de canas',        30, 'additional', 'tratamiento',  'malaga', 12.00, false, 31),
  (12, 'Color fantasía',            90, 'additional', 'tratamiento',  'malaga', 25.00, true,  32),
  (13, 'Mechas',                    90, 'additional', 'tratamiento',  'malaga', 25.00, true,  33),
  (14, 'Iluminaciones',             30, 'additional', 'tratamiento',  'malaga', 12.00, false, 34),

  -- ── TORREMOLINOS ──────────────────────────────────────────
  -- Corte
  (20, 'Corte',                     30, 'base',       'corte',        'torremolinos', 17.00, false, 1),
  (21, 'Corte Niño hasta 12 años',  30, 'base',       'corte',        'torremolinos', 15.00, false, 2),
  (22, 'Rapado',                    20, 'base',       'corte',        'torremolinos', 13.00, false, 3),
  -- Barba
  (23, 'Arreglo de barba a máquina',15, 'base',       'barba',        'torremolinos',  8.00, false, 10),
  (24, 'Arreglo de barba a navaja', 20, 'base',       'barba',        'torremolinos', 12.00, false, 11),
  (25, 'Afeitado + Ritual',         30, 'base',       'barba',        'torremolinos', 14.00, false, 12),
  -- Tratamientos
  (26, 'Color barba',               20, 'additional', 'tratamiento',  'torremolinos',  8.00, false, 30),
  (27, 'Reducción de canas',        30, 'additional', 'tratamiento',  'torremolinos', 12.00, false, 31),
  (28, 'Color fantasía',            90, 'additional', 'tratamiento',  'torremolinos', 25.00, true,  32),
  (29, 'Mechas',                    90, 'additional', 'tratamiento',  'torremolinos', 25.00, true,  33),
  (30, 'Iluminaciones',             30, 'additional', 'tratamiento',  'torremolinos', 12.00, false, 34);

-- 5. Asegurar que la secuencia de ids no choque con los ids manuales
SELECT setval(pg_get_serial_sequence('public.services','id'), (SELECT MAX(id) FROM public.services));
