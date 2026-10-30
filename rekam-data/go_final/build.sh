#!/bin/bash
export PATH="/usr/local/go/bin:$PATH"

echo "Menjalankan build untuk go_final..."
go build -o rekam-data .

if [ $? -eq 0 ]; then
    echo "✅ Build berhasil! File binary 'rekam-data' telah dibuat."
else
    echo "❌ Build gagal! Silakan cek error di atas."
fi
