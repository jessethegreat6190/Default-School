CREATE TABLE IF NOT EXISTS attendance_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  stream_id BIGINT UNSIGNED NULL,
  session_date DATE NOT NULL,
  term_id BIGINT UNSIGNED NULL,
  marked_by BIGINT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  notes TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attendance_session (school_id, class_id, session_date, stream_id),
  CONSTRAINT fk_att_sess_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_att_sess_class FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_att_sess_stream FOREIGN KEY (stream_id) REFERENCES streams(id),
  CONSTRAINT fk_att_sess_term FOREIGN KEY (term_id) REFERENCES terms(id),
  CONSTRAINT fk_att_sess_user FOREIGN KEY (marked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'present',
  check_in_time TIME NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attendance_entry (session_id, student_id),
  CONSTRAINT fk_att_entry_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_entry_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS timetable_slots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  stream_id BIGINT UNSIGNED NULL,
  subject_id BIGINT UNSIGNED NULL,
  teacher_id BIGINT UNSIGNED NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  room VARCHAR(100) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tt_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_tt_class FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_tt_stream FOREIGN KEY (stream_id) REFERENCES streams(id),
  CONSTRAINT fk_tt_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
  CONSTRAINT fk_tt_teacher FOREIGN KEY (teacher_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
