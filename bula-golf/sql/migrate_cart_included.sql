-- Run ONCE (phpMyAdmin > SQL). A cart comes with the round of golf, so it carries no hire fee.
-- (The app also ignores any fee on a cart, so this just tidies the stored values and the estimated-income figures.)
UPDATE assets SET fee = 0 WHERE kind = 'cart';
