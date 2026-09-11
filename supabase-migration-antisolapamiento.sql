-- ============================================================
-- Six40 Booking — Evitar citas solapadas del mismo barbero
-- ============================================================
-- El plugin ya comprueba la disponibilidad antes de insertar, pero entre esa
-- comprobación y el INSERT hay una ventana en la que dos personas pueden
-- confirmar el mismo hueco. Esto lo cierra a nivel de base de datos.
--
-- El rango es semiabierto [inicio, fin): dos citas encadenadas (10:00–10:40 y
-- 10:40–11:00) NO chocan, que es justo el comportamiento que quiere el plugin.
-- Las citas canceladas quedan fuera de la restricción.
--
-- Si el INSERT choca, Postgres devuelve el código 23P01 y el plugin muestra
-- "Esa hora acaba de ocuparse. Elige otra, por favor."

-- ------------------------------------------------------------
-- PASO 1 — Comprobar si YA hay solapamientos (si los hay, el PASO 2 falla).
-- ------------------------------------------------------------
-- Ejecuta esto primero y revisa el resultado:
--
--   SELECT a.id, b.id AS id_solapada, a.barber_id, a.date,
--          a.start_time, a.end_time, b.start_time, b.end_time,
--          a.customer_name, b.customer_name
--   FROM public.appointments a
--   JOIN public.appointments b
--     ON a.barber_id = b.barber_id
--    AND a.date      = b.date
--    AND a.id        < b.id
--    AND a.status   <> 'cancelled'
--    AND b.status   <> 'cancelled'
--    AND a.start_time < b.end_time
--    AND b.start_time < a.end_time
--   ORDER BY a.date, a.start_time;
--
-- Si devuelve filas, arréglalas a mano (cancelar o mover la sobrante) antes
-- de seguir. Si devuelve 0 filas, continúa.

-- ------------------------------------------------------------
-- PASO 2 — Crear la restricción (seguro de re-ejecutar).
-- ------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS btree_gist;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'appt_no_overlap'
  ) THEN
    ALTER TABLE public.appointments
      ADD CONSTRAINT appt_no_overlap
      EXCLUDE USING gist (
        barber_id WITH =,
        tsrange( (date + start_time), (date + end_time) ) WITH &&
      ) WHERE (status <> 'cancelled');
  END IF;
END $$;
