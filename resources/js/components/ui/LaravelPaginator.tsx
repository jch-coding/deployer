import { Pagination, PaginationContent, PaginationItem, PaginationLink, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { type Paginator } from '@/types/deployer';

export default function LaravelPaginator<T>({TPaginator}: {TPaginator: Paginator<T>}) {
    const links = TPaginator.links
    return (
        <Pagination className="mt-3">
            <PaginationContent>
                {TPaginator.prev_page_url && (
                    <PaginationItem>
                        <PaginationPrevious
                            href={
                                TPaginator.prev_page_url
                            }
                        />
                    </PaginationItem>
                )}
                {links.filter((_,idx) => idx > 0 && idx < links.length - 1 ).map((link) => (
                    <PaginationItem>
                        <PaginationLink
                            href={link.url}
                            isActive={link.active}
                        >
                            {link.label}
                        </PaginationLink>
                    </PaginationItem>
                ))}
                {TPaginator.next_page_url && (
                    <PaginationItem>
                        <PaginationNext
                            href={
                                TPaginator.next_page_url
                            }
                        />
                    </PaginationItem>
                )}
            </PaginationContent>
        </Pagination>
    )
}
