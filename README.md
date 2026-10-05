# 🎵 Amethyst Music

A lightweight fork of Purple Music, self-hosted web application to stream and organize your personal music library. Built with a responsive, dark-purple interface, it runs smoothly without heavy dependencies using **PHP** and **MY SQL**.

![PHP](https://img.shields.io/badge/PHP-%3E%3D%207.4-777BB4?style=flat-square&logo=php)
![My SQL](https://img.shields.io/badge/Database-MY_SQL-003B57?style=flat-square&logo=mysql)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

---
## 📸 Screenshots

<p align="center">
  <br>
  <img src="https://github.com/enzo-quirici/PurpleMusic/blob/main/screenshot/player.png" alt="Purple Music Phone Interface" width="10%">
  <img src="https://github.com/enzo-quirici/PurpleMusic/blob/main/screenshot/pc.png" alt="Purple Music Desktop Interface" width="40%">
  <br>
  <img src="https://github.com/enzo-quirici/PurpleMusic/blob/main/screenshot/admin.png" alt="Purple Music Admin Interface" width="40%">
</p>

# ❓ The SQL script might not create the user for the db correctly

## ✨ Features

- **🚀 One-Click and one SQL script Install**: Automated setup wizard on the first run and automated SQL script for database creation.
- **📱 Smart & Responsive UI**: Modern design optimized for desktop and mobile, featuring an app-like fullscreen player.
- **🎶 Multi-Format Support**: Plays and automatically calculates track length for `.mp3`, `.wav`, `.flac`, and `.ogg` files.
- **🏷️ ID3v2 Metadata Parser**: Automatically extracts title, artist, and embedded artwork from your MP3 files.
- **🖼️ Image Optimization**: Automatically resizes and converts covers to `.webp` to save bandwidth (requires PHP-GD extension).
- **🔀 Advanced Controls**: Shuffle, loop (track or queue), and dynamic queue management.
- **📂 Mixes & Playlists**: Create, edit, and share custom playlists among users.
- **🔒 Security & Control**: 
  - Session-based user/admin roles.
  - CSRF protection using secure tokens.
  - Rate-limiting on registration and file uploads.
  - Storage folders protected against arbitrary script execution via `.htaccess`.

---

## 🛠️ Prerequisites

To run Purple Music, you only need:
- A web server (Apache, Nginx, LiteSpeed, etc.)
- **PHP 7.4** or higher
- The following PHP extensions enabled:
  - `php-gd` *(Optional but highly recommended for image optimization)*

---

## 🚀 Installation & Deployment

1. **Clone the repository** into your web directory:
   ```bash
   git clone https://github.com/enzo-quirici/Amethyst_Music
   cd Amethyst_Music
   nano setup.sql
   # change the mysql password
   mysql -u root -p < setup.sql
   ```

This project is a fork of PurpleMusic (MIT License)
Original author: Axolat (https://github.com/Axolat000/PurpleMusic)
