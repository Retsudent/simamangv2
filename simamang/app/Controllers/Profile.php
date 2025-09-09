<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class Profile extends BaseController
{
    protected $session;
    protected $db;

    public function __construct()
    {
        $this->session = session();
        $this->db = \Config\Database::connect();
        
        // Cek apakah user sudah login
        if (!$this->session->get('isLoggedIn')) {
            redirect()->to('/login')->send();
            exit;
        }
    }

    public function index()
    {
        $userId = $this->session->get('user_id');
        $userRole = $this->session->get('role');
        
        // Ambil data user berdasarkan role
        $userData = $this->getUserData($userId, $userRole);
        
        if (!$userData) {
            return redirect()->back()->with('error', 'Data user tidak ditemukan');
        }
        
        $data = [
            'title' => 'Profil Saya - SIMAMANG',
            'user' => $userData,
            'role' => $userRole
        ];
        
        return view('profile/index', $data);
    }

    public function edit()
    {
        $userId = $this->session->get('user_id');
        $userRole = $this->session->get('role');
        
        // Ambil data user berdasarkan role
        $userData = $this->getUserData($userId, $userRole);
        
        if (!$userData) {
            return redirect()->back()->with('error', 'Data user tidak ditemukan');
        }
        
        $data = [
            'title' => 'Edit Profil - SIMAMANG',
            'user' => $userData,
            'role' => $userRole
        ];
        
        return view('profile/edit', $data);
    }

    public function update()
    {
        $userId = $this->session->get('user_id');
        $userRole = $this->session->get('role');
        
        $request = service('request');
        
        // Validasi input
        $rules = [
            'nama' => 'required|min_length[3]',
            'email' => 'permit_empty|valid_email',
            'no_hp' => 'permit_empty|min_length[10]'
        ];
        
        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        
        // Data yang akan diupdate
        $updateData = [
            'nama' => $request->getPost('nama'),
            'email' => $request->getPost('email') ?: null,
            'no_hp' => $request->getPost('no_hp') ?: null,
            'alamat' => $request->getPost('alamat') ?: null,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Tambahkan field khusus untuk setiap role
        if ($userRole === 'siswa') {
            $updateData['tempat_lahir'] = $request->getPost('tempat_lahir') ?: null;
            $updateData['tanggal_lahir'] = $request->getPost('tanggal_lahir') ?: null;
            $updateData['jenis_kelamin'] = $request->getPost('jenis_kelamin') ?: null;
            $updateData['kelas'] = $request->getPost('kelas') ?: null;
            $updateData['jurusan'] = $request->getPost('jurusan') ?: null;
        } elseif ($userRole === 'pembimbing') {
            $updateData['instansi'] = $request->getPost('instansi') ?: null;
            $updateData['jabatan'] = $request->getPost('jabatan') ?: null;
            $updateData['bidang_keahlian'] = $request->getPost('bidang_keahlian') ?: null;
        }
        
        try {
            // Update data di tabel users (tabel utama)
            $userUpdateData = [
                'nama' => $updateData['nama'],
                'email' => $updateData['email'],
                'no_hp' => $updateData['no_hp'],
                'updated_at' => $updateData['updated_at']
            ];
            $this->db->table('users')->where('id', $userId)->update($userUpdateData);
            
            // Update data role-specific berdasarkan role
            if ($userRole === 'siswa') {
                $this->db->table('siswa')->where('user_id', $userId)->update($updateData);
            } elseif ($userRole === 'pembimbing') {
                $this->db->table('pembimbing')->where('user_id', $userId)->update($updateData);
            } elseif ($userRole === 'admin') {
                $this->db->table('admin')->where('user_id', $userId)->update($updateData);
            }
            
            // Update session nama jika berubah
            if ($updateData['nama'] !== $this->session->get('nama')) {
                $this->session->set('nama', $updateData['nama']);
            }
            
            return redirect()->to('/profile')->with('success', 'Profil berhasil diperbarui');
        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', 'Gagal memperbarui profil: ' . $e->getMessage());
        }
    }

    public function updatePhoto()
    {
        $userId = $this->session->get('user_id');
        $userRole = $this->session->get('role');
        
        $request = service('request');
        $file = $request->getFile('foto_profil');
        
        // Validasi file
        if (!$file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
            return redirect()->back()->with('error', 'Pilih file foto yang valid');
        }
        
        // Validasi tipe file
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!in_array($file->getMimeType(), $allowedTypes)) {
            return redirect()->back()->with('error', 'Tipe file tidak didukung. Gunakan JPG, PNG, atau GIF');
        }
        
        // Validasi ukuran file (max 2MB)
        if ($file->getSize() > 2 * 1024 * 1024) {
            return redirect()->back()->with('error', 'Ukuran file terlalu besar. Maksimal 2MB');
        }
        
        try {
            // Hapus foto lama jika ada
            $oldPhoto = null;
            if ($userRole === 'siswa') {
                $oldPhoto = $this->db->table('siswa')->where('user_id', $userId)->get()->getRowArray();
            } elseif ($userRole === 'pembimbing') {
                $oldPhoto = $this->db->table('pembimbing')->where('user_id', $userId)->get()->getRowArray();
            } elseif ($userRole === 'admin') {
                $oldPhoto = $this->db->table('admin')->where('user_id', $userId)->get()->getRowArray();
            }
            
            if ($oldPhoto && $oldPhoto['foto_profil']) {
                $oldPhotoPath = WRITEPATH . 'uploads/profile/' . $oldPhoto['foto_profil'];
                if (file_exists($oldPhotoPath)) {
                    unlink($oldPhotoPath);
                }
            }
            
            // Generate nama file unik dengan timestamp
            $extension = $file->getExtension();
            $timestamp = time();
            $newName = $timestamp . '_' . $file->getRandomName();
            
            // Pindahkan file ke folder uploads/profile
            $uploadPath = WRITEPATH . 'uploads/profile/';
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }
            
            $file->move($uploadPath, $newName);
            
            // Update database di tabel users (tabel utama)
            $this->db->table('users')->where('id', $userId)->update([
                'foto_profil' => $newName,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            // Sinkronkan juga ke tabel role-specific agar konsisten saat login ulang
            if ($userRole === 'siswa') {
                $this->db->table('siswa')->where('user_id', $userId)->update(['foto_profil' => $newName]);
            } elseif ($userRole === 'pembimbing') {
                $this->db->table('pembimbing')->where('user_id', $userId)->update(['foto_profil' => $newName]);
            } elseif ($userRole === 'admin') {
                $this->db->table('admin')->where('user_id', $userId)->update(['foto_profil' => $newName]);
            }
            
            // Update database di tabel role-specific juga berdasarkan username
            $username = $this->session->get('username');
            if ($userRole === 'siswa') {
                $this->db->table('siswa')->where('username', $username)->update([
                    'foto_profil' => $newName,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            } elseif ($userRole === 'pembimbing') {
                $this->db->table('pembimbing')->where('username', $username)->update([
                    'foto_profil' => $newName,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            } elseif ($userRole === 'admin') {
                $this->db->table('admin')->where('username', $username)->update([
                    'foto_profil' => $newName,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            // Update session foto_profil
            $this->session->set('foto_profil', $newName);
            
            // Clear cache dan redirect dengan parameter untuk memaksa refresh
            return redirect()->to('/profile?refresh=' . $timestamp)->with('success', 'Foto profil berhasil diperbarui');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Gagal mengupload foto: ' . $e->getMessage());
        }
    }

    public function changePassword()
    {
        $userId = $this->session->get('user_id');
        $userRole = $this->session->get('role');
        
        $request = service('request');
        
        // Validasi input
        $rules = [
            'current_password' => 'required',
            'new_password' => 'required|min_length[6]',
            'confirm_password' => 'required|matches[new_password]'
        ];
        
        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        
        // Ambil data user dari tabel users (tabel utama)
        $userData = $this->db->table('users')->where('id', $userId)->get()->getRowArray();
        
        if (!$userData) {
            return redirect()->back()->with('error', 'Data user tidak ditemukan');
        }
        
        // Verifikasi password lama
        if (!password_verify($request->getPost('current_password'), $userData['password'])) {
            return redirect()->back()->withInput()->with('error', 'Password lama tidak sesuai');
        }
        
        // Hash password baru
        $newPasswordHash = password_hash($request->getPost('new_password'), PASSWORD_DEFAULT);
        
        try {
            // Update password di tabel users (tabel utama)
            $this->db->table('users')->where('id', $userId)->update([
                'password' => $newPasswordHash,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            return redirect()->to('/profile')->with('success', 'Password berhasil diubah');
        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', 'Gagal mengubah password: ' . $e->getMessage());
        }
    }

    public function refreshPhoto()
    {
        // Endpoint ringan untuk mengambil nama file foto_profil terbaru dari database
        try {
            $userId = $this->session->get('user_id');
            if (!$userId) {
                return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthenticated']);
            }

            $row = $this->db->table('users')->select('foto_profil, updated_at')->where('id', $userId)->get()->getRowArray();
            $filename = $row['foto_profil'] ?? null;
            return $this->response->setJSON([
                'file' => $filename,
                'url' => $filename ? base_url('photo.php?file=' . $filename . '&type=profile') : null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Failed to fetch photo']);
        }
    }

    private function getUserData($userId, $userRole)
    {
        // Ambil data dari tabel users (tabel utama)
        $userData = $this->db->table('users')->where('id', $userId)->get()->getRowArray();
        
        if (!$userData) {
            return null;
        }
        
        // Ambil data role-specific berdasarkan role
        $roleData = null;
        if ($userRole === 'siswa') {
            $roleData = $this->db->table('siswa')->where('user_id', $userId)->get()->getRowArray();
        } elseif ($userRole === 'pembimbing') {
            $roleData = $this->db->table('pembimbing')->where('user_id', $userId)->get()->getRowArray();
        } elseif ($userRole === 'admin') {
            $roleData = $this->db->table('admin')->where('user_id', $userId)->get()->getRowArray();
        }
        
        // Gabungkan data users dengan data role-specific dan pertahankan foto_profil dari users bila role-specific kosong
        if ($roleData) {
            $usersFoto = $userData['foto_profil'] ?? null;
            $userData = array_merge($userData, $roleData);
            if ((!isset($userData['foto_profil']) || empty($userData['foto_profil'])) && $usersFoto) {
                $userData['foto_profil'] = $usersFoto;
            }
        }
        
        // Tambahkan field foto_profil jika belum ada
        if (!isset($userData['foto_profil'])) {
            $userData['foto_profil'] = null;
        }
        
        return $userData;
    }
}
