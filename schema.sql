DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS class_meetings;
DROP TABLE IF EXISTS meeting_days;


CREATE TABLE classes (
  code INTEGER PRIMARY KEY,
  class VARCHAR(32) NOT NULL,
  year INTEGER NOT NULL,
  sem INTEGER NOT NULL
);

CREATE TABLE class_meetings (
  code INTEGER REFERENCES classes(code),
  time_start TIME,
  time_end TIME,
  PRIMARY KEY (code, time_start, time_end)
);

CREATE TABLE meeting_days (
  code INTEGER REFERENCES class_meetings(code),
  time_start TIME REFERENCES class_meetings(time_start),
  time_end TIME REFERENCES class_meetings(time_end),
  day ENUM('MO', 'TU', 'WE', 'TH', 'FR', 'SA') NOT NULL,
  PRIMARY KEY (code, time_start, time_end, day)
);
