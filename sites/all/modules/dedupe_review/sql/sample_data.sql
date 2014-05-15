-- TODO:
-- * auto_incremented ids
-- * assigned user id

replace into civicrm_contact (id, first_name, last_name, display_name, preferred_language) values
    (101, '熙', '從維', '從維熙', 'zh'),
    (201, 'Weixi', 'Cong', 'Cong Weixi', 'zh'),
    (102, 'Shengtao', 'Ye', 'Ye Shengtao', 'zh'),
    (202, '陶', '葉聖', '葉聖陶', 'zh,en'),
    (103, 'Анна', 'Ахматова', 'Анна Ахматова', 'ru'),
    (203, 'АННА', 'АХМАТОВА', 'АННА АХМАТОВА', 'en'),
    (104, 'sergei', 'santiago', 'sergei santiago', 'he'),
    (204, 'Sergei de la santiago', 'santiago', 'Sergei de la santiago', 'he'),
    (105, 'Adam', 'Wright', 'Adam Wright', 'en'),
    (205, 'Adam', 'Wight', 'Adam Wight', 'en');


replace into civicrm_email (contact_id, email, is_primary) values
    (101, 'congwiexi@gmail.com', 1),
    (201, 'congwiexi@gmail.com', 1),
    (102, 'yeshengtao@gmail.com', 1),
    (202, 'yeshengtao@gmail.com', 1),
    (103, 'annaax@yandex.ru', 1),
    (203, 'aaxmat@lbl.gov', 1),
    (104, 'yankee@berkeley.edu', 1),
    (204, 'yankee@berkeley.edu', 1),
    (105, 'awight@wikimedia.org', 1),
    (205, 'awight@wikimedia.org', 1);

replace into civicrm_address (contact_id, street_address, country_id, is_primary) values
    (101, '2 spacious courtyard', 1045, 1),
    (201, '2 spacious courtyard, apt 2', 1045, 1),
    (102, 'ROOM 1028, NEW WORLD BUILDING, NO.9 FUZHOU SOUTH ROAD', 1045, 1),
    (202, 'ROOM 1028, NEW WORLD BUILDING, NO.9 FUZHOU SOUTH ROAD', 1045, 1),
    (103, '43/5 Selbyansk', 1177, 1),
    (203, '33 Litenii Ul.', 1177, 1),
    (104, '45 Castle Rd', 1228, 1),
    (204, '45 Castle Rd', 1228, 1),
    (105, '143 New Montgomery St', 1228, 1),
    (205, '143 New Montgomery St', 1228, 1);

-- Civi 4.4: replace into civicrm_contribution (contact_id, financial_type_id, payment_instrument_id, total_amount, source, receive_date) values
replace into civicrm_contribution (contact_id, contribution_type_id, payment_instrument_id, total_amount, source, receive_date) values
    (101, 1, 1, '1.60', '10 CNY', '20140101'),
    (201, 1, 1, '1.60', '10 CNY', '20140101'),
    (102, 1, 1, '0.83', '5.2 CNY', '20140101'),
    (202, 1, 1, '3.00', '3 USD', '20140101'),
    (103, 1, 1, '2.81', '100 RUB', '20140101'),
    (203, 1, 1, '2.81', '100 RUB', '20140101'),
    (104, 1, 1, '5.62', '200 RUB', '20140101'),
    (204, 1, 1, '27.74', '20 EUR', '20140101'),
    (105, 1, 1, '1.01', '1.01 USD', '20140101'),
    (205, 1, 1, '1.01', '1.01 USD', '20140101');

replace into dedupe_review_queue (id, job_id, old_id, new_id, match_description, action_id) values
    (1, 1, 101, 201, '{"email": "Exact match"}', 3),
    (2, 1, 102, 202, '{"email": "Exact match"}', 3),
    (3, 1, 103, 203, '{"name": "Case-insensitive match"}', 3),
    (4, 1, 104, 204, '{"name": "Fuzzy match"}', 3);
