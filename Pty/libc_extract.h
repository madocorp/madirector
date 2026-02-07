
typedef int pid_t;

int openpty(int *amaster, int *aslave, char *name, void *termp, void *winp);
int open(const char *path, int flags);
int dup2(int oldfd, int newfd);
int socketpair(int domain, int type, int protocol, int sv[2]);
ssize_t read(int fd, void *buf, size_t count);
ssize_t write(int fd, const void *buf, size_t count);
int close(int fd);
int execvp(const char *file, char *const argv[]);
int fcntl(int fd, int cmd, ...);
pid_t setsid(void);
