CREATE TABLE `attendance_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `teacher_id` int(10) UNSIGNED NOT NULL,
  `work_date` date NOT NULL,
  `check_in_at` datetime DEFAULT NULL,
  `check_out_at` datetime DEFAULT NULL,
  `location_type` enum('home','official','workout','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'home',
  `location_detail` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `task_detail` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkin_lat` decimal(10,7) DEFAULT NULL,
  `checkin_lng` decimal(10,7) DEFAULT NULL,
  `checkout_lat` decimal(10,7) DEFAULT NULL,
  `checkout_lng` decimal(10,7) DEFAULT NULL,
  `checkin_photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkin_photo_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_photo_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_ip` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `groups` (
  `group_id` int(10) UNSIGNED NOT NULL,
  `group_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `groups`
--

INSERT INTO `groups` (`group_id`, `group_name`) VALUES
(10, 'กลุ่มสาระการงานอาชีพ'),
(3, 'กลุ่มสาระคณิตศาสตร์'),
(11, 'กลุ่มสาระภาษาต่างประเทศ'),
(2, 'กลุ่มสาระภาษาไทย'),
(4, 'กลุ่มสาระวิทยาศาสตร์และเทคโนโลยี'),
(9, 'กลุ่มสาระศิลปะ'),
(5, 'กลุ่มสาระสังคมศึกษา'),
(6, 'กลุ่มสาระสุขศึกษาและพลศึกษา'),
(12, 'กิจกรรมพัฒนาผู้เรียน'),
(14, 'ครูอัตราจ้าง'),
(13, 'ผู้บริหาร'),
(15, 'พนักงานราชการ'),
(16, 'ลูกจ้าง'),
(1, 'ไม่ระบุ');

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `teacher_id` int(10) UNSIGNED NOT NULL,
  `teacher_username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `teacher_password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `teacher_fullname` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_id` int(10) UNSIGNED DEFAULT NULL,
  `teacher_position` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_teacher_workdate` (`teacher_id`,`work_date`),
  ADD KEY `idx_work_date` (`work_date`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`group_id`),
  ADD UNIQUE KEY `uk_group_name` (`group_name`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`teacher_id`),
  ADD UNIQUE KEY `uk_teacher_username` (`teacher_username`),
  ADD KEY `idx_teacher_group` (`group_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `groups`
--
ALTER TABLE `groups`
  MODIFY `group_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `teacher_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD CONSTRAINT `fk_log_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `teachers`
--
ALTER TABLE `teachers`
  ADD CONSTRAINT `fk_teacher_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`group_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
