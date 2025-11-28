<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class HealthController extends Controller
{
    /**
     * Health check endpoint for monitoring
     */
    public function check(): JsonResponse
    {
        $checks = [
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'version' => config('app.version', '1.0.0'),
            'environment' => config('app.env'),
            'services' => [
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'storage' => $this->checkStorage(),
                'queue' => $this->checkQueue(),
            ]
        ];

        // Determine overall health status
        $serviceStatuses = collect($checks['services'])->pluck('status');
        $overallStatus = $serviceStatuses->contains('error') ? 'error' : 'healthy';
        
        $checks['status'] = $overallStatus;

        return response()->json($checks, $overallStatus === 'healthy' ? 200 : 503);
    }

    /**
     * Check database connectivity
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            
            // Test a simple query
            $userCount = DB::table('users')->count();
            
            return [
                'status' => 'healthy',
                'message' => 'Database connection successful',
                'details' => [
                    'connection' => DB::connection()->getName(),
                    'users_count' => $userCount
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Database connection failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check cache system
     */
    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . time();
            $value = 'test';
            
            Cache::put($key, $value, 10);
            $retrieved = Cache::get($key);
            Cache::forget($key);
            
            if ($retrieved === $value) {
                return [
                    'status' => 'healthy',
                    'message' => 'Cache system working',
                    'driver' => config('cache.default')
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Cache system not working properly'
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Cache system failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check storage system
     */
    private function checkStorage(): array
    {
        try {
            $disk = config('filesystems.default');
            $path = 'health-check-' . time() . '.txt';
            $content = 'Health check test';
            
            \Storage::put($path, $content);
            $exists = \Storage::exists($path);
            \Storage::delete($path);
            
            return [
                'status' => $exists ? 'healthy' : 'error',
                'message' => $exists ? 'Storage system working' : 'Storage system failed',
                'driver' => $disk
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Storage system failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check queue system
     */
    private function checkQueue(): array
    {
        try {
            $connection = config('queue.default');
            
            // For database queue, check if jobs table exists
            if ($connection === 'database') {
                $jobsCount = DB::table('jobs')->count();
                $failedJobsCount = DB::table('failed_jobs')->count();
                
                return [
                    'status' => 'healthy',
                    'message' => 'Queue system working',
                    'driver' => $connection,
                    'details' => [
                        'pending_jobs' => $jobsCount,
                        'failed_jobs' => $failedJobsCount
                    ]
                ];
            }
            
            return [
                'status' => 'healthy',
                'message' => 'Queue system configured',
                'driver' => $connection
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Queue system failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Simple status check (for load balancer health checks)
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString()
        ]);
    }
}