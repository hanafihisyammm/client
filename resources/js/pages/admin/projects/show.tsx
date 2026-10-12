import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ArrowLeft, Edit, Users, FileText, Calendar, User } from 'lucide-react';

interface User {
    id: number;
    name: string;
    email: string;
}

interface Report {
    id: number;
    uuid: string;
    title: string;
    status: string;
    created_at: string;
    reporter: User;
}

interface Project {
    id: number;
    name: string;
    description: string;
    status: 'active' | 'inactive' | 'completed';
    created_at: string;
    updated_at: string;
    creator: User;
    users: User[];
    reports: Report[];
}

interface Props {
    project: Project;
}

export default function ProjectShow({ project }: Props) {
    const getStatusColor = (status: string) => {
        switch (status) {
            case 'active':
                return 'bg-green-100 text-green-800';
            case 'inactive':
                return 'bg-gray-100 text-gray-800';
            case 'completed':
                return 'bg-blue-100 text-blue-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    };

    const getReportStatusColor = (status: string) => {
        switch (status) {
            case 'pending':
                return 'bg-yellow-100 text-yellow-800';
            case 'in_progress':
                return 'bg-blue-100 text-blue-800';
            case 'submit_for_review':
                return 'bg-purple-100 text-purple-800';
            case 'approved':
                return 'bg-green-100 text-green-800';
            case 'rejected':
                return 'bg-red-100 text-red-800';
            case 'closed':
                return 'bg-gray-100 text-gray-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    };

    const formatReportStatus = (status: string) => {
        return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    return (
        <AppLayout>
            <Head title={`Project: ${project.name}`} />
            
            <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div className="p-6">
                        <div className="flex items-center justify-between mb-6">
                            <div className="flex items-center gap-4">
                                <Link href="/projects">
                                    <Button variant="outline" size="sm">
                                        <ArrowLeft className="w-4 h-4" />
                                    </Button>
                                </Link>
                                <div>
                                    <h1 className="text-2xl font-semibold text-gray-900">{project.name}</h1>
                                    <div className="flex items-center gap-2 mt-1">
                                        <Badge className={getStatusColor(project.status)}>
                                            {project.status}
                                        </Badge>
                                        <span className="text-sm text-gray-500">
                                            Created {formatDate(project.created_at)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <Link href={`/projects/${project.uuid}/edit`}>
                                <Button className="flex items-center gap-2">
                                    <Edit className="w-4 h-4" />
                                    Edit Project
                                </Button>
                            </Link>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            {/* Project Details */}
                            <div className="lg:col-span-1">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Project Details</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        {project.description && (
                                            <div>
                                                <h4 className="font-medium text-gray-700 mb-1">Description</h4>
                                                <p className="text-gray-600">{project.description}</p>
                                            </div>
                                        )}
                                        
                                        <div className="flex items-center gap-2 text-sm text-gray-600">
                                            <User className="w-4 h-4" />
                                            <span>Created by {project.creator.name}</span>
                                        </div>
                                        
                                        <div className="flex items-center gap-2 text-sm text-gray-600">
                                            <Calendar className="w-4 h-4" />
                                            <span>Last updated {formatDate(project.updated_at)}</span>
                                        </div>

                                        <div className="pt-4 border-t">
                                            <div className="flex items-center gap-4 text-sm">
                                                <div className="flex items-center gap-1">
                                                    <Users className="w-4 h-4 text-gray-400" />
                                                    <span>{project.users.length} clients</span>
                                                </div>
                                                <div className="flex items-center gap-1">
                                                    <FileText className="w-4 h-4 text-gray-400" />
                                                    <span>{project.reports.length} reports</span>
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Assigned Clients */}
                                <Card className="mt-6">
                                    <CardHeader>
                                        <CardTitle>Assigned Clients</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {project.users.length === 0 ? (
                                            <p className="text-gray-500">No clients assigned</p>
                                        ) : (
                                            <div className="space-y-3">
                                                {project.users.map((user) => (
                                                    <div key={user.id} className="flex items-center gap-3 p-2 rounded-lg bg-gray-50">
                                                        <div className="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                                            <span className="text-sm font-medium text-blue-700">
                                                                {user.name.charAt(0).toUpperCase()}
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <p className="font-medium text-gray-900">{user.name}</p>
                                                            <p className="text-sm text-gray-500">{user.email}</p>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>

                            {/* Reports */}
                            <div className="lg:col-span-2">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Project Reports ({project.reports.length})</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {project.reports.length === 0 ? (
                                            <div className="text-center py-8">
                                                <FileText className="w-12 h-12 text-gray-400 mx-auto mb-4" />
                                                <p className="text-gray-500">No reports found for this project</p>
                                            </div>
                                        ) : (
                                            <div className="rounded-md border">
                                                <Table>
                                                    <TableHeader>
                                                        <TableRow>
                                                            <TableHead>Report Title</TableHead>
                                                            <TableHead>Reporter</TableHead>
                                                            <TableHead>Status</TableHead>
                                                            <TableHead>Created</TableHead>
                                                        </TableRow>
                                                    </TableHeader>
                                                    <TableBody>
                                                        {project.reports.map((report) => (
                                                            <TableRow
                                                                key={report.id}
                                                                className="cursor-pointer hover:bg-gray-50"
                                                                onClick={() => router.get(`/reports/${report.uuid}`)}
                                                            >
                                                                <TableCell className="font-medium">
                                                                    <Link 
                                                                        href={`/reports/${report.uuid}`}
                                                                        onClick={(e) => e.stopPropagation()}
                                                                        className="text-blue-600 hover:text-blue-800 hover:underline"
                                                                    >
                                                                        {report.title}
                                                                    </Link>
                                                                </TableCell>
                                                                <TableCell>{report.reporter.name}</TableCell>
                                                                <TableCell>
                                                                    <Badge className={getReportStatusColor(report.status)}>
                                                                        {formatReportStatus(report.status)}
                                                                    </Badge>
                                                                </TableCell>
                                                                <TableCell className="text-sm text-gray-500">
                                                                    {formatDate(report.created_at)}
                                                                </TableCell>
                                                            </TableRow>
                                                        ))}
                                                    </TableBody>
                                                </Table>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}