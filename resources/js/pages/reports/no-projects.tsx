import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, AlertCircle, Users } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';

interface PageProps {
    userRole: 'client' | 'admin' | 'super_admin';
    message: string;
}

export default function NoProjects({ userRole, message }: PageProps) {
    return (
        <AppLayout>
            <Head title="No Projects Assigned" />
            
            <div className="space-y-6 px-4 py-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Button 
                        variant="outline" 
                        size="sm"
                        onClick={() => router.get('/dashboard')}
                    >
                        <ArrowLeft className="w-4 h-4 mr-2" />
                        Back to Reports
                    </Button>
                    
                    <div className="flex-1">
                        <h1 className="text-2xl font-bold">Create New Report</h1>
                        <p className="text-muted-foreground">
                            Report an issue or request a new feature
                        </p>
                    </div>
                </div>

                {/* No Projects Message */}
                <div className="max-w-2xl mx-auto">
                    <Card className="border-amber-200 bg-amber-50">
                        <CardHeader className="text-center pb-4">
                            <div className="mx-auto w-12 h-12 bg-amber-100 rounded-full flex items-center justify-center mb-4">
                                <AlertCircle className="w-6 h-6 text-amber-600" />
                            </div>
                            <CardTitle className="text-amber-800">No Projects Assigned</CardTitle>
                        </CardHeader>
                        <CardContent className="text-center space-y-4">
                            <p className="text-amber-700">
                                {message}
                            </p>
                            
                            <div className="bg-white rounded-lg p-4 border border-amber-200">
                                <div className="flex items-center gap-3 text-sm text-amber-800">
                                    <Users className="w-5 h-5" />
                                    <div className="text-left">
                                        <p className="font-medium">What you can do:</p>
                                        <ul className="mt-2 space-y-1 text-amber-700">
                                            <li>• Contact your system administrator</li>
                                            <li>• Request to be assigned to a project</li>
                                            <li>• Wait for project assignment notification</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div className="pt-4">
                                <Button 
                                    onClick={() => router.get('/dashboard')}
                                    className="bg-amber-600 hover:bg-amber-700"
                                >
                                    Return to Reports
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}