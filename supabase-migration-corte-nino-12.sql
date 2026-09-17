-- ============================================================
-- Six40 Booking — "Corte Niño" pasa a llamarse "Corte Niño hasta 12 años"
-- ============================================================
-- Ejecutar en: Supabase → SQL Editor. Seguro de re-ejecutar.
--
-- El nombre sale tal cual en /reservar, en el resumen, en el correo de
-- confirmación, en Google Calendar y en el listado de citas del admin
-- (también en las citas antiguas, porque el nombre se lee de esta tabla).
-- Afecta a las dos filas: Málaga (id 2) y Torremolinos (id 21).

UPDATE public.services
SET name = 'Corte Niño hasta 12 años'
WHERE name = 'Corte Niño'
RETURNING id, name, location, price;
