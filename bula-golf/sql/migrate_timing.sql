-- Run ONCE (phpMyAdmin > SQL). Round times per course (minutes): how long a 9 or 18 hole round is expected to take,
-- and when a cart still out counts as overdue. Used by the carts-out animation, the dashboard and the "slow rounds" report.
-- Admins change them in Settings > Round times.
ALTER TABLE courses
  ADD COLUMN t9 SMALLINT NOT NULL DEFAULT 135,
  ADD COLUMN t18 SMALLINT NOT NULL DEFAULT 270,
  ADD COLUMN late9 SMALLINT NOT NULL DEFAULT 210,
  ADD COLUMN late18 SMALLINT NOT NULL DEFAULT 360;
