import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

// Presentation-only fixtures. No MonthlyRecord ids, meter ids, API calls,
// persistence, verification actions, or shared analytics state.
const demoRows = [
    { period: 'Example month 1', kwh: 120 },
    { period: 'Example month 2', kwh: 135 },
    { period: 'Example month 3', kwh: 128 },
];

export function SyntheticDemoPreview({ onReset }: { onReset: () => void }) {
    return (
        <Card
            aria-label="Isolated synthetic demo preview"
            className="rounded-[13px] border-[#d9a633] bg-[#fff5e3] shadow-none"
        >
            <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                <CardTitle className="text-[17px]">
                    Synthetic demo preview{' '}
                    <Badge variant="outline">
                        Synthetic — not Meralco data
                    </Badge>
                </CardTitle>
                <Button variant="outline" onClick={onReset}>
                    Reset demo
                </Button>
            </CardHeader>
            <CardContent>
                <p className="mb-3 text-sm">
                    Preview only. These fictional values are not uploaded,
                    stored, verified, or included in official totals, analytics
                    or forecasts. Official billing records below remain
                    separate.
                </p>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Example period</TableHead>
                            <TableHead>Fictional kWh</TableHead>
                            <TableHead>Source</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {demoRows.map((row) => (
                            <TableRow key={row.period}>
                                <TableCell>{row.period}</TableCell>
                                <TableCell>{row.kwh}</TableCell>
                                <TableCell>Synthetic demo</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}
