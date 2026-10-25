-- Database Setup Script for Public Transport Analytics System
CREATE DATABASE IF NOT EXISTS transport_system;
USE transport_system;

-- 1. USERS TABLE
DROP TABLE IF EXISTS Feedback;
DROP TABLE IF EXISTS Passengers;
DROP TABLE IF EXISTS GPS_Locations;
DROP TABLE IF EXISTS Trips;
DROP TABLE IF EXISTS Stops;
DROP TABLE IF EXISTS Routes;
DROP TABLE IF EXISTS Vehicles;
DROP TABLE IF EXISTS Drivers;
DROP TABLE IF EXISTS Users;

CREATE TABLE Users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Driver', 'Passenger') NOT NULL
);

-- 2. DRIVERS TABLE
CREATE TABLE Drivers (
    driver_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    license_number VARCHAR(50) NOT NULL UNIQUE,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

-- 3. VEHICLES TABLE
CREATE TABLE Vehicles (
    vehicle_id INT AUTO_INCREMENT PRIMARY KEY,
    plate_number VARCHAR(20) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL,
    capacity INT NOT NULL
);

-- 4. ROUTES TABLE
CREATE TABLE Routes (
    route_id INT AUTO_INCREMENT PRIMARY KEY,
    route_name VARCHAR(100) NOT NULL,
    origin VARCHAR(100) NOT NULL,
    destination VARCHAR(100) NOT NULL
);

-- 5. STOPS TABLE
CREATE TABLE Stops (
    stop_id INT AUTO_INCREMENT PRIMARY KEY,
    route_id INT NOT NULL,
    stop_name VARCHAR(100) NOT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    FOREIGN KEY (route_id) REFERENCES Routes(route_id) ON DELETE CASCADE
);

-- 6. TRIPS TABLE
CREATE TABLE Trips (
    trip_id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    driver_id INT NOT NULL,
    route_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    status ENUM('Scheduled', 'In Progress', 'Completed', 'Cancelled') NOT NULL,
    FOREIGN KEY (vehicle_id) REFERENCES Vehicles(vehicle_id),
    FOREIGN KEY (driver_id) REFERENCES Drivers(driver_id),
    FOREIGN KEY (route_id) REFERENCES Routes(route_id)
);

-- 7. GPS LOCATIONS TABLE
CREATE TABLE GPS_Locations (
    gps_id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    speed DECIMAL(5,2),
    timestamp DATETIME NOT NULL,
    FOREIGN KEY (vehicle_id) REFERENCES Vehicles(vehicle_id) ON DELETE CASCADE
);

-- 8. PASSENGERS TABLE
CREATE TABLE Passengers (
    passenger_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

-- 9. FEEDBACK TABLE
CREATE TABLE Feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    passenger_id INT NOT NULL,
    rating INT CHECK (rating BETWEEN 1 AND 5),
    comments TEXT,
    FOREIGN KEY (passenger_id) REFERENCES Passengers(passenger_id) ON DELETE CASCADE
);

-- ===================================================
-- SAMPLE DATA INSERTION (Minimum 10 records per main table)
-- ===================================================

-- Insert Users (12 records)
INSERT INTO Users (fullname, email, password, role) VALUES
('Admin User', 'admin@transport.com', 'admin123', 'Admin'),
('John Doe', 'john.driver@transport.com', 'pass123', 'Driver'),
('Jane Smith', 'jane.driver@transport.com', 'pass123', 'Driver'),
('Mark Lee', 'mark.driver@transport.com', 'pass123', 'Driver'),
('Alice Brown', 'alice.driver@transport.com', 'pass123', 'Driver'),
('Charlie Green', 'charlie.driver@transport.com', 'pass123', 'Driver'),
('David Wilson', 'david.p@gmail.com', 'pass123', 'Passenger'),
('Emma Davis', 'emma.p@gmail.com', 'pass123', 'Passenger'),
('Frank Miller', 'frank.p@gmail.com', 'pass123', 'Passenger'),
('Grace Taylor', 'grace.p@gmail.com', 'pass123', 'Passenger'),
('Hannah Anderson', 'hannah.p@gmail.com', 'pass123', 'Passenger'),
('Ian Thomas', 'ian.p@gmail.com', 'pass123', 'Passenger');

-- Insert Drivers (5 records)
INSERT INTO Drivers (user_id, license_number) VALUES
(2, 'DL-1001-A'),
(3, 'DL-1002-B'),
(4, 'DL-1003-C'),
(5, 'DL-1004-D'),
(6, 'DL-1005-E');

-- Insert Vehicles (10 records)
INSERT INTO Vehicles (plate_number, type, capacity) VALUES
('ABC-1234', 'Bus', 50),
('XYZ-5678', 'Bus', 50),
('LMN-9101', 'Jeepney', 20),
('JKL-1121', 'Jeepney', 20),
('OPQ-3141', 'Van', 15),
('RST-5161', 'Bus', 45),
('UVW-7181', 'Jeepney', 22),
('XYZ-9202', 'Bus', 55),
('AAA-1111', 'Van', 12),
('BBB-2222', 'Van', 14);

-- Insert local Ilocos Norte routes (Demo data; not an official route registry)
INSERT INTO Routes (route_name, origin, destination) VALUES
('Batac–Laoag', 'Batac Terminal', 'Laoag Terminal'),
('Batac–Paoay', 'Batac Terminal', 'Paoay Terminal'),
('Paoay–Laoag', 'Paoay Terminal', 'Laoag Terminal'),
('Laoag–San Nicolas', 'Laoag Terminal', 'San Nicolas Terminal'),
('San Nicolas–Batac', 'San Nicolas Terminal', 'Batac Terminal');

-- Local stop coordinates are illustrative sample points, not live vehicle GPS.
INSERT INTO Stops (route_id, stop_name, latitude, longitude) VALUES
(1, 'Laoag Terminal', 18.19750000, 120.59310000),
(1, 'San Nicolas Plaza Stop', 18.17250000, 120.59600000),
(1, 'Robinsons Laoag Stop', 18.12900000, 120.56200000),
(1, 'Batac Terminal', 18.05530000, 120.56360000),
(2, 'Batac Terminal', 18.05530000, 120.56360000),
(2, 'Batac City Hall Terminal', 18.05670000, 120.56410000),
(2, 'Paoay Terminal', 18.06690000, 120.53780000),
(3, 'Paoay Terminal', 18.06690000, 120.53780000),
(3, 'Paoay Church Terminal', 18.06780000, 120.53880000),
(3, 'Laoag Public Market Terminal', 18.18780000, 120.59310000),
(3, 'Laoag Terminal', 18.19750000, 120.59310000),
(4, 'Laoag Terminal', 18.19750000, 120.59310000),
(4, 'San Nicolas Terminal', 18.17250000, 120.59600000),
(5, 'San Nicolas Terminal', 18.17250000, 120.59600000),
(5, 'Batac Terminal', 18.05530000, 120.56360000);

-- Insert Passengers (6 records)
INSERT INTO Passengers (user_id) VALUES (7), (8), (9), (10), (11), (12);

-- Insert Trips (10 records)
INSERT INTO Trips (vehicle_id, driver_id, route_id, start_time, end_time, status) VALUES
(1, 1, 1, '2026-09-20 08:00:00', '2026-09-20 09:00:00', 'Completed'),
(2, 2, 2, '2026-09-20 08:30:00', '2026-09-20 09:45:00', 'Completed'),
(3, 3, 3, '2026-09-20 09:00:00', NULL, 'In Progress'),
(4, 4, 4, '2026-09-20 10:00:00', NULL, 'Scheduled'),
(5, 5, 5, '2026-09-20 07:00:00', '2026-09-20 08:15:00', 'Completed'),
(6, 1, 1, '2026-09-20 11:00:00', NULL, 'Scheduled'),
(7, 2, 2, '2026-09-20 12:00:00', NULL, 'Cancelled'),
(8, 3, 3, '2026-09-20 13:00:00', NULL, 'Scheduled'),
(9, 4, 4, '2026-09-20 14:00:00', NULL, 'Scheduled'),
(10, 5, 5, '2026-09-20 15:00:00', NULL, 'Scheduled');

-- Insert Feedback (10 records)
INSERT INTO Feedback (passenger_id, rating, comments) VALUES
(1, 5, 'Excellent service and on time!'),
(2, 4, 'Clean vehicle and smooth driving.'),
(3, 3, 'A bit crowded but acceptable.'),
(4, 2, 'Vehicle arrived 15 minutes late.'),
(5, 5, 'Very comfortable journey.'),
(1, 4, 'Good driver behavior.'),
(2, 1, 'Drive was reckless.'),
(3, 5, 'Top notch public transport!'),
(4, 4, 'Satisfied with the service.'),
(5, 3, 'Average experience.');
