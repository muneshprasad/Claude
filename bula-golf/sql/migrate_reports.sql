-- Run ONCE (phpMyAdmin > SQL), after migrate_roles.sql.
-- Keeps each golfer's hotel and country as they were at check-in, so "resort guests" reports stay true for past dates.
ALTER TABLE checkin_golfers ADD COLUMN hotel VARCHAR(150) NULL, ADD COLUMN country VARCHAR(80) NULL;
-- Existing check-ins: use what the guest record currently holds (best available).
UPDATE checkin_golfers cg JOIN guests g ON g.id = cg.guest_id SET cg.hotel = g.hotel, cg.country = g.country;

-- Optional membership start date, so "rounds in the membership year" can use the real year (without it: the 12 months ending at expiry).
ALTER TABLE members ADD COLUMN starts_on DATE NULL AFTER email;
