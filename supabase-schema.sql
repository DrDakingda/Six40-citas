-- ============================================================
-- Six40 Booking System — Supabase Schema (v3.0)
-- Ejecutar en: Supabase → SQL Editor
--
-- Estado CONSOLIDADO a jul 2026: incluye ya todas las migraciones
-- (supabase-migration-*.sql). En una instalación desde cero basta
-- con ejecutar este archivo; las migraciones quedan como histórico
-- para instalaciones que ya estaban en marcha.
-- ============================================================

-- ============================================================
-- 1. TABLA: Barberos
-- ============================================================
CREATE TABLE IF NOT EXISTS public.barbers (
  id              SMALLINT PRIMARY KEY,
  name            TEXT NOT NULL,
  location        TEXT NOT NULL CHECK (location IN ('malaga', 'torremolinos')),
  active          BOOLEAN NOT NULL DEFAULT true,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Datos iniciales
-- El id 6 (Graciela en Torremolinos) se conserva inactivo: Graciela solo
-- atiende en Málaga, pero el registro mantiene la integridad referencial
-- de citas históricas.
INSERT INTO public.barbers (id, name, location, active) VALUES
  (1, 'Samuel Puertas', 'malaga', true),
  (2, 'Graciela Arcos', 'malaga', true),
  (3, 'Adrián Ortigosa', 'malaga', true),
  (4, 'Alejandro Alfonso', 'malaga', true),
  (5, 'Antonio Pérez', 'torremolinos', true),
  (6, 'Graciela Arcos', 'torremolinos', false),
  (7, 'Juan Jose García', 'torremolinos', true),
  (8, 'Adrián Ortigosa', 'torremolinos', true)
ON CONFLICT (id) DO NOTHING;

-- ============================================================
-- 2. TABLA: Horarios por barbero y día
-- ============================================================
-- day_of_week: 0=Lunes, 1=Martes, 2=Miércoles, 3=Jueves, 4=Viernes, 5=Sábado, 6=Domingo
CREATE TABLE IF NOT EXISTS public.barber_schedules (
  id              BIGSERIAL PRIMARY KEY,
  barber_id       SMALLINT NOT NULL REFERENCES public.barbers(id) ON DELETE CASCADE,
  day_of_week     SMALLINT NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
  start_time      TIME NOT NULL,
  end_time        TIME NOT NULL,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (barber_id, day_of_week, start_time)
);

-- Índices
CREATE INDEX IF NOT EXISTS idx_barber_schedules_barber ON public.barber_schedules (barber_id);
CREATE INDEX IF NOT EXISTS idx_barber_schedules_day    ON public.barber_schedules (day_of_week);

-- RLS
ALTER TABLE public.barber_schedules ENABLE ROW LEVEL SECURITY;
CREATE POLICY "sched_read" ON public.barber_schedules FOR SELECT USING (true);

-- Datos iniciales (MÁLAGA)
INSERT INTO public.barber_schedules (barber_id, day_of_week, start_time, end_time) VALUES
  -- Graciela (Lun-Sab 10-14)
  (2, 0, '10:00'::time, '14:00'::time),
  (2, 1, '10:00'::time, '14:00'::time),
  (2, 2, '10:00'::time, '14:00'::time),
  (2, 3, '10:00'::time, '14:00'::time),
  (2, 4, '10:00'::time, '14:00'::time),
  (2, 5, '10:00'::time, '14:00'::time),

  -- Alejandro (Lun-Vie 10-18)
  (4, 0, '10:00'::time, '18:00'::time),
  (4, 1, '10:00'::time, '18:00'::time),
  (4, 2, '10:00'::time, '18:00'::time),
  (4, 3, '10:00'::time, '18:00'::time),
  (4, 4, '10:00'::time, '18:00'::time),

  -- Samuel (Lun-Vie 14-21, Sab 10-14)
  (1, 0, '14:00'::time, '21:00'::time),
  (1, 1, '14:00'::time, '21:00'::time),
  (1, 2, '14:00'::time, '21:00'::time),
  (1, 3, '14:00'::time, '21:00'::time),
  (1, 4, '14:00'::time, '21:00'::time),
  (1, 5, '10:00'::time, '14:00'::time),

  -- Adrián Málaga (Mar y Jue 14-20)
  (3, 1, '14:00'::time, '20:00'::time),
  (3, 3, '14:00'::time, '20:00'::time)
ON CONFLICT DO NOTHING;

-- Datos iniciales (TORREMOLINOS)
INSERT INTO public.barber_schedules (barber_id, day_of_week, start_time, end_time) VALUES
  -- Juan (Lun-Mar 14-20, Mié 10-20, Jue-Vie 12-20, Sab 10-14)
  (7, 0, '14:00'::time, '20:00'::time),
  (7, 1, '14:00'::time, '20:00'::time),
  (7, 2, '10:00'::time, '20:00'::time),
  (7, 3, '12:00'::time, '20:00'::time),
  (7, 4, '12:00'::time, '20:00'::time),
  (7, 5, '10:00'::time, '14:00'::time),

  -- Antonio (Lun-Vie 10-14 y 16-20, Sab 10-14)
  (5, 0, '10:00'::time, '14:00'::time),
  (5, 0, '16:00'::time, '20:00'::time),
  (5, 1, '10:00'::time, '14:00'::time),
  (5, 1, '16:00'::time, '20:00'::time),
  (5, 2, '10:00'::time, '14:00'::time),
  (5, 2, '16:00'::time, '20:00'::time),
  (5, 3, '10:00'::time, '14:00'::time),
  (5, 3, '16:00'::time, '20:00'::time),
  (5, 4, '10:00'::time, '14:00'::time),
  (5, 4, '16:00'::time, '20:00'::time),
  (5, 5, '10:00'::time, '14:00'::time),

  -- Adrián Torremolinos (Lun 10-14, Mar 10-13, Mié 14-20, Jue 10-13, Vie 10-16)
  (8, 0, '10:00'::time, '14:00'::time),
  (8, 1, '10:00'::time, '13:00'::time),
  (8, 2, '14:00'::time, '20:00'::time),
  (8, 3, '10:00'::time, '13:00'::time),
  (8, 4, '10:00'::time, '16:00'::time)
ON CONFLICT DO NOTHING;

-- ============================================================
-- 3. TABLA: Servicios
-- ============================================================
CREATE TABLE IF NOT EXISTS public.services (
  id              BIGSERIAL PRIMARY KEY,
  name            TEXT NOT NULL,
  duration        SMALLINT NOT NULL CHECK (duration > 0), -- minutos
  type            TEXT NOT NULL CHECK (type IN ('base', 'additional')), -- compat (no se usa en menú libre)
  category        TEXT,          -- 'corte' | 'barba' | 'tratamiento'
  location        TEXT CHECK (location IS NULL OR location IN ('malaga', 'torremolinos')), -- NULL = ambos
  price           NUMERIC(6,2),  -- precio orientativo
  price_from      BOOLEAN NOT NULL DEFAULT false, -- true = "desde X €"
  active          BOOLEAN NOT NULL DEFAULT true,
  display_order   SMALLINT DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Mismo nombre permitido en distintos locales (Corte de Málaga vs Torremolinos)
CREATE UNIQUE INDEX IF NOT EXISTS uniq_service_name_location
  ON public.services (name, COALESCE(location, ''));

-- Índices
CREATE INDEX IF NOT EXISTS idx_services_type   ON public.services (type);
CREATE INDEX IF NOT EXISTS idx_services_active ON public.services (active);

-- RLS
ALTER TABLE public.services ENABLE ROW LEVEL SECURITY;
CREATE POLICY "svc_read" ON public.services FOR SELECT USING (active = true);

-- Datos iniciales POR LOCAL (precios orientativos)
INSERT INTO public.services (id, name, duration, type, category, location, price, price_from, display_order) VALUES
  -- ── MÁLAGA ──
  (1,  'Corte',                     30, 'base',       'corte',       'malaga', 15.00, false, 1),
  (2,  'Corte Niño',                30, 'base',       'corte',       'malaga', 12.50, false, 2),
  (3,  'Rapado',                    20, 'base',       'corte',       'malaga', 10.00, false, 3),
  (4,  'Arreglo de barba a máquina',15, 'base',       'barba',       'malaga',  8.00, false, 10),
  (5,  'Arreglo de barba a navaja', 20, 'base',       'barba',       'malaga', 10.00, false, 11),
  (6,  'Afeitado',                  30, 'base',       'barba',       'malaga', 12.00, false, 12),
  (10, 'Color barba',               20, 'additional', 'tratamiento', 'malaga',  8.00, false, 30),
  (11, 'Reducción de canas',        30, 'additional', 'tratamiento', 'malaga', 12.00, false, 31),
  (12, 'Color fantasía',            90, 'additional', 'tratamiento', 'malaga', 25.00, true,  32),
  (13, 'Mechas',                    90, 'additional', 'tratamiento', 'malaga', 25.00, true,  33),
  (14, 'Iluminaciones',             30, 'additional', 'tratamiento', 'malaga', 12.00, false, 34),
  -- ── TORREMOLINOS ──
  (20, 'Corte',                     30, 'base',       'corte',       'torremolinos', 17.00, false, 1),
  (21, 'Corte Niño',                30, 'base',       'corte',       'torremolinos', 15.00, false, 2),
  (22, 'Rapado',                    20, 'base',       'corte',       'torremolinos', 13.00, false, 3),
  (23, 'Arreglo de barba a máquina',15, 'base',       'barba',       'torremolinos',  8.00, false, 10),
  (24, 'Arreglo de barba a navaja', 20, 'base',       'barba',       'torremolinos', 12.00, false, 11),
  (25, 'Afeitado + Ritual',         30, 'base',       'barba',       'torremolinos', 14.00, false, 12),
  (26, 'Color barba',               20, 'additional', 'tratamiento', 'torremolinos',  8.00, false, 30),
  (27, 'Reducción de canas',        30, 'additional', 'tratamiento', 'torremolinos', 12.00, false, 31),
  (28, 'Color fantasía',            90, 'additional', 'tratamiento', 'torremolinos', 25.00, true,  32),
  (29, 'Mechas',                    90, 'additional', 'tratamiento', 'torremolinos', 25.00, true,  33),
  (30, 'Iluminaciones',             30, 'additional', 'tratamiento', 'torremolinos', 12.00, false, 34)
ON CONFLICT DO NOTHING;

-- Duraciones base normalizadas: barbas 20 min.
-- (Las combinaciones corte+barba=40, corte+mechas=60, etc. las calcula el plugin.)
UPDATE public.services SET duration = 20 WHERE category = 'barba';

-- La secuencia no debe chocar con los ids insertados a mano
SELECT setval(pg_get_serial_sequence('public.services','id'), (SELECT MAX(id) FROM public.services));

-- ============================================================
-- 4. TABLA: Citas (actualizada)
-- ============================================================
CREATE TABLE IF NOT EXISTS public.appointments (
  id              BIGSERIAL PRIMARY KEY,
  location        TEXT NOT NULL CHECK (location IN ('malaga', 'torremolinos')),
  date            DATE NOT NULL,
  start_time      TIME NOT NULL,
  end_time        TIME NOT NULL,
  barber_id       SMALLINT NOT NULL REFERENCES public.barbers(id) ON DELETE RESTRICT,
  customer_name   TEXT NOT NULL,
  customer_email  TEXT NOT NULL,
  customer_phone  TEXT,
  status          TEXT NOT NULL DEFAULT 'confirmed'
                  CHECK (status IN ('confirmed', 'completed', 'cancelled', 'no_show')),
  notes           TEXT,
  google_event_id    TEXT,   -- evento en el calendario del barbero (para detectar anulaciones)
  google_calendar_id TEXT,
  google_events      TEXT,   -- JSON con todos los eventos (barbero + local) para sincronizar
  manage_token       TEXT,   -- token para el enlace de gestión (cancelar/modificar)
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Índices
CREATE INDEX IF NOT EXISTS idx_appt_date            ON public.appointments (date);
CREATE INDEX IF NOT EXISTS idx_appt_location_date   ON public.appointments (location, date);
CREATE INDEX IF NOT EXISTS idx_appt_barber_date     ON public.appointments (barber_id, date);
CREATE INDEX IF NOT EXISTS idx_appt_status          ON public.appointments (status);
CREATE INDEX IF NOT EXISTS idx_appt_email           ON public.appointments (customer_email);
CREATE INDEX IF NOT EXISTS idx_appt_manage_token    ON public.appointments (manage_token);

-- Un barbero no puede tener dos citas solapadas (rango semiabierto: las citas
-- encadenadas 10:00-10:40 y 10:40-11:00 no chocan). Las canceladas no cuentan.
CREATE EXTENSION IF NOT EXISTS btree_gist;
DO $$
BEGIN
  IF NOT EXISTS ( SELECT 1 FROM pg_constraint WHERE conname = 'appt_no_overlap' ) THEN
    ALTER TABLE public.appointments
      ADD CONSTRAINT appt_no_overlap
      EXCLUDE USING gist (
        barber_id WITH =,
        tsrange( (date + start_time), (date + end_time) ) WITH &&
      ) WHERE (status <> 'cancelled');
  END IF;
END $$;

-- Trigger updated_at
CREATE OR REPLACE FUNCTION public.handle_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END; $$;

DROP TRIGGER IF EXISTS set_updated_at ON public.appointments;
CREATE TRIGGER set_updated_at
  BEFORE UPDATE ON public.appointments
  FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();

-- RLS
ALTER TABLE public.appointments ENABLE ROW LEVEL SECURITY;
CREATE POLICY "appt_read"   ON public.appointments FOR SELECT USING (true);
CREATE POLICY "appt_insert" ON public.appointments FOR INSERT WITH CHECK (true);
CREATE POLICY "appt_update" ON public.appointments FOR UPDATE USING (true);

-- ============================================================
-- 5. TABLA: Servicios por cita (muchos-a-muchos)
-- ============================================================
CREATE TABLE IF NOT EXISTS public.appointment_services (
  id              BIGSERIAL PRIMARY KEY,
  appointment_id  BIGSERIAL NOT NULL REFERENCES public.appointments(id) ON DELETE CASCADE,
  service_id      BIGSERIAL NOT NULL REFERENCES public.services(id) ON DELETE RESTRICT,
  sequence        SMALLINT NOT NULL DEFAULT 0, -- orden 0, 1, 2...
  UNIQUE (appointment_id, service_id)
);

-- Índices
CREATE INDEX IF NOT EXISTS idx_appt_svc_appointment ON public.appointment_services (appointment_id);
CREATE INDEX IF NOT EXISTS idx_appt_svc_service    ON public.appointment_services (service_id);

-- RLS
ALTER TABLE public.appointment_services ENABLE ROW LEVEL SECURITY;
CREATE POLICY "appt_svc_read"   ON public.appointment_services FOR SELECT USING (true);
CREATE POLICY "appt_svc_insert" ON public.appointment_services FOR INSERT WITH CHECK (true);
CREATE POLICY "appt_svc_delete" ON public.appointment_services FOR DELETE USING (true);

-- ============================================================
-- 6. TABLA: Días libres específicos por barbero
-- ============================================================
CREATE TABLE IF NOT EXISTS public.barber_days_off (
  id              BIGSERIAL PRIMARY KEY,
  barber_id       SMALLINT NOT NULL REFERENCES public.barbers(id) ON DELETE CASCADE,
  date            DATE NOT NULL,
  note            TEXT DEFAULT '',
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (barber_id, date)
);

-- Índices
CREATE INDEX IF NOT EXISTS idx_days_off_barber_date ON public.barber_days_off (barber_id, date);
CREATE INDEX IF NOT EXISTS idx_days_off_date        ON public.barber_days_off (date);

-- RLS
ALTER TABLE public.barber_days_off ENABLE ROW LEVEL SECURITY;
CREATE POLICY "doff_read"   ON public.barber_days_off FOR SELECT USING (true);
CREATE POLICY "doff_insert" ON public.barber_days_off FOR INSERT WITH CHECK (true);
CREATE POLICY "doff_delete" ON public.barber_days_off FOR DELETE USING (true);

-- ============================================================
-- 7. VISTAS
-- ============================================================

-- Vista: citas de hoy con servicios y duración
CREATE OR REPLACE VIEW public.appointments_today AS
SELECT
  a.id,
  a.location,
  a.date,
  a.start_time,
  a.end_time,
  EXTRACT(EPOCH FROM (a.end_time - a.start_time))/60 AS duration_minutes,
  a.barber_id,
  b.name AS barber_name,
  a.customer_name,
  a.customer_email,
  a.customer_phone,
  a.status,
  STRING_AGG(s.name, ', ' ORDER BY aps.sequence) AS services
FROM public.appointments a
  LEFT JOIN public.barbers b ON a.barber_id = b.id
  LEFT JOIN public.appointment_services aps ON a.id = aps.appointment_id
  LEFT JOIN public.services s ON aps.service_id = s.id
WHERE a.date = CURRENT_DATE
GROUP BY a.id, b.name
ORDER BY a.start_time;

-- Vista: disponibilidad de barberos por día
CREATE OR REPLACE VIEW public.barber_availability AS
SELECT
  b.id,
  b.name,
  b.location,
  bs.day_of_week,
  bs.start_time,
  bs.end_time,
  (bs.end_time - bs.start_time) AS working_hours
FROM public.barbers b
  LEFT JOIN public.barber_schedules bs ON b.id = bs.barber_id
WHERE b.active = true;
